<?php

namespace App\Libraries\Tools;

/**
 * Execution environment manager.
 *
 * Manages execution targets (local, SSH, Docker, Kubernetes) so other tools
 * can transparently run commands and transfer files on remote or containerised hosts.
 *
 * Actions:
 *   list     – show configured targets
 *   set      – switch active target
 *   status   – connectivity / OS / available tools on a target
 *   register – add a new target
 *   remove   – delete a target
 *   exec     – run a command on the active (or specified) target
 *   upload   – copy a local file to the target
 *   download – copy a remote file from the target
 */
class ExecTargetTool extends BaseTool
{
    protected string $name = 'exec_target';
    protected string $description = 'Manage execution targets (local, SSH, Docker, K8s) and run commands remotely';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled'        => true,
            'timeout'        => 30,
            'targets_file'   => 'writable/agent/config/exec_targets.json',
            'active_target'  => 'local',
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'action'  => ['type' => 'string', 'required' => true, 'enum' => ['list', 'set', 'status', 'register', 'remove', 'exec', 'upload', 'download']],
            'target'  => ['type' => 'string', 'required' => false, 'description' => 'Target name (defaults to active target)'],
            'command' => ['type' => 'string', 'required' => false, 'description' => 'Command to execute (action=exec)'],
            'cwd'     => ['type' => 'string', 'required' => false, 'description' => 'Working directory for exec'],
            'local_path'  => ['type' => 'string', 'required' => false],
            'remote_path' => ['type' => 'string', 'required' => false],
            // register fields
            'type'     => ['type' => 'string', 'required' => false, 'enum' => ['local', 'ssh', 'docker', 'docker_compose', 'kubernetes']],
            'host'     => ['type' => 'string', 'required' => false],
            'user'     => ['type' => 'string', 'required' => false],
            'port'     => ['type' => 'int',    'required' => false, 'default' => 22],
            'key'      => ['type' => 'string', 'required' => false, 'description' => 'SSH key path'],
            'container' => ['type' => 'string', 'required' => false, 'description' => 'Docker container name/id'],
            'service'   => ['type' => 'string', 'required' => false, 'description' => 'Docker Compose service'],
            'namespace' => ['type' => 'string', 'required' => false, 'description' => 'K8s namespace'],
            'pod'       => ['type' => 'string', 'required' => false, 'description' => 'K8s pod name'],
            'shell'     => ['type' => 'string', 'required' => false, 'default' => '/bin/sh'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        $targets = $this->loadTargets();
        $active  = $this->config['active_target'] ?? 'local';

        switch ($args['action']) {
            case 'list':
                return $this->success([
                    'active'  => $active,
                    'targets' => $targets,
                ]);

            case 'set':
                if ($err = $this->requireArgs($args, ['target'])) return $err;
                $name = $args['target'];
                if ($name !== 'local' && !isset($targets[$name])) {
                    return $this->error("Target not found: {$name}");
                }
                $this->config['active_target'] = $name;
                $this->saveActiveTarget($name);
                return $this->success(['active' => $name], "Switched to target: {$name}");

            case 'status':
                $name   = $args['target'] ?? $active;
                $target = $this->resolveTarget($name, $targets);
                if (!$target) return $this->error("Target not found: {$name}");
                return $this->probeTarget($name, $target);

            case 'register':
                if ($err = $this->requireArgs($args, ['target', 'type'])) return $err;
                $name = $args['target'];
                $entry = $this->buildTargetEntry($args);
                $targets[$name] = $entry;
                $this->saveTargets($targets);
                return $this->success(['target' => $name, 'config' => $entry], "Registered target: {$name}");

            case 'remove':
                if ($err = $this->requireArgs($args, ['target'])) return $err;
                $name = $args['target'];
                if ($name === 'local') return $this->error('Cannot remove the local target');
                if (!isset($targets[$name])) return $this->error("Target not found: {$name}");
                unset($targets[$name]);
                $this->saveTargets($targets);
                if ($active === $name) $this->saveActiveTarget('local');
                return $this->success(['removed' => $name]);

            case 'exec':
                if ($err = $this->requireArgs($args, ['command'])) return $err;
                $name   = $args['target'] ?? $active;
                $target = $this->resolveTarget($name, $targets);
                if (!$target) return $this->error("Target not found: {$name}");
                return $this->execOnTarget($target, $args['command'], $args['cwd'] ?? null);

            case 'upload':
                if ($err = $this->requireArgs($args, ['local_path', 'remote_path'])) return $err;
                $name   = $args['target'] ?? $active;
                $target = $this->resolveTarget($name, $targets);
                if (!$target) return $this->error("Target not found: {$name}");
                return $this->uploadToTarget($target, $args['local_path'], $args['remote_path']);

            case 'download':
                if ($err = $this->requireArgs($args, ['remote_path', 'local_path'])) return $err;
                $name   = $args['target'] ?? $active;
                $target = $this->resolveTarget($name, $targets);
                if (!$target) return $this->error("Target not found: {$name}");
                return $this->downloadFromTarget($target, $args['remote_path'], $args['local_path']);

            default:
                return $this->error("Unknown action: {$args['action']}");
        }
    }

    // ── target persistence ────────────────────────────────────

    private function loadTargets(): array
    {
        $path = $this->targetsPath();
        if (!file_exists($path)) return [];
        $data = json_decode(file_get_contents($path), true);
        return $data['targets'] ?? [];
    }

    private function saveTargets(array $targets): void
    {
        $path = $this->targetsPath();
        $dir  = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, json_encode(['targets' => $targets, 'active' => $this->config['active_target'] ?? 'local'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function saveActiveTarget(string $name): void
    {
        $path = $this->targetsPath();
        $data = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        $data['active'] = $name;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function targetsPath(): string
    {
        return WRITEPATH . 'agent/config/exec_targets.json';
    }

    private function resolveTarget(string $name, array $targets): ?array
    {
        if ($name === 'local') return ['type' => 'local'];
        return $targets[$name] ?? null;
    }

    private function buildTargetEntry(array $args): array
    {
        $entry = ['type' => $args['type']];
        foreach (['host', 'user', 'port', 'key', 'container', 'service', 'namespace', 'pod', 'shell'] as $k) {
            if (isset($args[$k]) && $args[$k] !== '') $entry[$k] = $args[$k];
        }
        return $entry;
    }

    // ── execution backends ────────────────────────────────────

    private function execOnTarget(array $target, string $command, ?string $cwd = null): array
    {
        $type = $target['type'] ?? 'local';
        switch ($type) {
            case 'local':
                return $this->execLocal($command, $cwd);
            case 'ssh':
                return $this->execSsh($target, $command, $cwd);
            case 'docker':
                return $this->execDocker($target, $command, $cwd);
            case 'docker_compose':
                return $this->execDockerCompose($target, $command, $cwd);
            case 'kubernetes':
                return $this->execKubernetes($target, $command, $cwd);
            default:
                return $this->error("Unsupported target type: {$type}");
        }
    }

    private function execLocal(string $command, ?string $cwd = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd ?? getcwd());
        if (!is_resource($process)) return $this->error('Failed to start local process');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return $this->success(['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'target' => 'local']);
    }

    private function execSsh(array $target, string $command, ?string $cwd = null): array
    {
        $user = $target['user'] ?? 'root';
        $host = $target['host'] ?? '';
        $port = $target['port'] ?? 22;
        $key  = $target['key'] ?? '';

        if (!$host) return $this->error('SSH target requires a host');

        $sshCmd = 'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10';
        if ($key) $sshCmd .= " -i " . escapeshellarg($key);
        $sshCmd .= " -p {$port} " . escapeshellarg("{$user}@{$host}");

        $remoteCmd = $cwd ? "cd " . escapeshellarg($cwd) . " && {$command}" : $command;
        $sshCmd .= " " . escapeshellarg($remoteCmd);

        return $this->execLocal($sshCmd);
    }

    private function execDocker(array $target, string $command, ?string $cwd = null): array
    {
        $container = $target['container'] ?? '';
        if (!$container) return $this->error('Docker target requires a container name');
        $shell = $target['shell'] ?? '/bin/sh';

        $dockerCmd = "docker exec";
        if ($cwd) $dockerCmd .= " -w " . escapeshellarg($cwd);
        $dockerCmd .= " " . escapeshellarg($container) . " {$shell} -c " . escapeshellarg($command);

        return $this->execLocal($dockerCmd);
    }

    private function execDockerCompose(array $target, string $command, ?string $cwd = null): array
    {
        $service = $target['service'] ?? '';
        if (!$service) return $this->error('Docker Compose target requires a service name');
        $shell = $target['shell'] ?? '/bin/sh';

        $dcCmd = "docker compose exec";
        if ($cwd) $dcCmd .= " -w " . escapeshellarg($cwd);
        $dcCmd .= " " . escapeshellarg($service) . " {$shell} -c " . escapeshellarg($command);

        return $this->execLocal($dcCmd);
    }

    private function execKubernetes(array $target, string $command, ?string $cwd = null): array
    {
        $pod = $target['pod'] ?? '';
        $ns  = $target['namespace'] ?? 'default';
        if (!$pod) return $this->error('Kubernetes target requires a pod name');
        $shell = $target['shell'] ?? '/bin/sh';

        $remoteCmd = $cwd ? "cd " . escapeshellarg($cwd) . " && {$command}" : $command;
        $k8sCmd = "kubectl exec -n " . escapeshellarg($ns) . " " . escapeshellarg($pod) . " -- {$shell} -c " . escapeshellarg($remoteCmd);

        return $this->execLocal($k8sCmd);
    }

    // ── file transfer ─────────────────────────────────────────

    private function uploadToTarget(array $target, string $local, string $remote): array
    {
        if (!file_exists($local)) return $this->error("Local file not found: {$local}");

        $type = $target['type'] ?? 'local';
        switch ($type) {
            case 'local':
                $dir = dirname($remote);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                copy($local, $remote);
                return $this->success(['uploaded' => $remote]);

            case 'ssh':
                $user = $target['user'] ?? 'root';
                $host = $target['host'] ?? '';
                $port = $target['port'] ?? 22;
                $key  = $target['key'] ?? '';
                $scpCmd = 'scp -o StrictHostKeyChecking=no';
                if ($key) $scpCmd .= " -i " . escapeshellarg($key);
                $scpCmd .= " -P {$port} " . escapeshellarg($local) . " " . escapeshellarg("{$user}@{$host}:{$remote}");
                return $this->execLocal($scpCmd);

            case 'docker':
                $container = $target['container'] ?? '';
                return $this->execLocal("docker cp " . escapeshellarg($local) . " " . escapeshellarg("{$container}:{$remote}"));

            case 'docker_compose':
                $service = $target['service'] ?? '';
                $idCmd = "docker compose ps -q " . escapeshellarg($service);
                $idResult = $this->execLocal($idCmd);
                if (!$idResult['success']) return $idResult;
                $containerId = trim($idResult['data']['stdout']);
                return $this->execLocal("docker cp " . escapeshellarg($local) . " " . escapeshellarg("{$containerId}:{$remote}"));

            case 'kubernetes':
                $pod = $target['pod'] ?? '';
                $ns  = $target['namespace'] ?? 'default';
                return $this->execLocal("kubectl cp " . escapeshellarg($local) . " " . escapeshellarg("{$ns}/{$pod}:{$remote}"));

            default:
                return $this->error("Upload not supported for target type: {$type}");
        }
    }

    private function downloadFromTarget(array $target, string $remote, string $local): array
    {
        $type = $target['type'] ?? 'local';
        $dir  = dirname($local);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        switch ($type) {
            case 'local':
                if (!file_exists($remote)) return $this->error("Remote file not found: {$remote}");
                copy($remote, $local);
                return $this->success(['downloaded' => $local]);

            case 'ssh':
                $user = $target['user'] ?? 'root';
                $host = $target['host'] ?? '';
                $port = $target['port'] ?? 22;
                $key  = $target['key'] ?? '';
                $scpCmd = 'scp -o StrictHostKeyChecking=no';
                if ($key) $scpCmd .= " -i " . escapeshellarg($key);
                $scpCmd .= " -P {$port} " . escapeshellarg("{$user}@{$host}:{$remote}") . " " . escapeshellarg($local);
                return $this->execLocal($scpCmd);

            case 'docker':
                $container = $target['container'] ?? '';
                return $this->execLocal("docker cp " . escapeshellarg("{$container}:{$remote}") . " " . escapeshellarg($local));

            case 'docker_compose':
                $service = $target['service'] ?? '';
                $idResult = $this->execLocal("docker compose ps -q " . escapeshellarg($service));
                if (!$idResult['success']) return $idResult;
                $containerId = trim($idResult['data']['stdout']);
                return $this->execLocal("docker cp " . escapeshellarg("{$containerId}:{$remote}") . " " . escapeshellarg($local));

            case 'kubernetes':
                $pod = $target['pod'] ?? '';
                $ns  = $target['namespace'] ?? 'default';
                return $this->execLocal("kubectl cp " . escapeshellarg("{$ns}/{$pod}:{$remote}") . " " . escapeshellarg($local));

            default:
                return $this->error("Download not supported for target type: {$type}");
        }
    }

    // ── probe / health ────────────────────────────────────────

    private function probeTarget(string $name, array $target): array
    {
        $type = $target['type'] ?? 'local';
        $probe = $this->execOnTarget($target, 'uname -s && uname -m && whoami && pwd && which git ctags node python3 php go cargo rustc 2>/dev/null || true');

        if (!$probe['success']) {
            return $this->success([
                'target'    => $name,
                'type'      => $type,
                'reachable' => false,
                'error'     => $probe['error'] ?? $probe['data']['stderr'] ?? 'Connection failed',
            ]);
        }

        $lines = array_filter(explode("\n", trim($probe['data']['stdout'])));
        $os    = $lines[0] ?? 'unknown';
        $arch  = $lines[1] ?? 'unknown';
        $user  = $lines[2] ?? 'unknown';
        $cwd   = $lines[3] ?? 'unknown';
        $tools = array_slice($lines, 4);

        return $this->success([
            'target'    => $name,
            'type'      => $type,
            'reachable' => ($probe['data']['exit_code'] ?? 1) === 0 || !empty($os),
            'os'        => $os,
            'arch'      => $arch,
            'user'      => $user,
            'cwd'       => $cwd,
            'available_tools' => $tools,
        ]);
    }
}
