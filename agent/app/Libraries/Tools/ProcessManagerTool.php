<?php

namespace App\Libraries\Tools;

/**
 * Start, monitor, and stop background processes.
 * Manages process lifecycle beyond what shell_exec provides.
 */
class ProcessManagerTool extends BaseTool
{
    protected string $name = 'process_manager';
    protected string $description = 'Start, monitor, and stop background processes with lifecycle management';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 10,
            'state_dir' => 'writable/agent/processes',
            'max_processes' => 20,
            'allowed_commands' => [], // empty = allow all
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Action: start, stop, status, list, tail, kill_all',
            ],
            'command' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Command to start (for start action)',
            ],
            'name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Process name/label (for start action)',
            ],
            'pid' => [
                'type' => 'int',
                'required' => false,
                'description' => 'Process ID (for stop/status/tail actions)',
            ],
            'cwd' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Working directory for the process',
            ],
            'tail_lines' => [
                'type' => 'int',
                'required' => false,
                'description' => 'Number of output lines to return for tail (default: 50)',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        $stateDir = $this->config['state_dir'] ?? 'writable/agent/processes';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        return match ($args['action']) {
            'start'    => $this->startProcess($args, $stateDir),
            'stop'     => $this->stopProcess($args, $stateDir),
            'status'   => $this->processStatus($args, $stateDir),
            'list'     => $this->listProcesses($stateDir),
            'tail'     => $this->tailProcess($args, $stateDir),
            'kill_all' => $this->killAll($stateDir),
            default    => $this->error("Unknown action: {$args['action']}. Use: start, stop, status, list, tail, kill_all"),
        };
    }

    private function startProcess(array $args, string $stateDir): array
    {
        if ($err = $this->requireArgs($args, ['command'])) return $err;

        $command = $args['command'];
        $name = $args['name'] ?? basename(explode(' ', $command)[0]);
        $cwd = $args['cwd'] ?? getcwd();

        // Check allowed commands
        $allowed = $this->config['allowed_commands'] ?? [];
        if (!empty($allowed)) {
            $cmd = explode(' ', trim($command))[0];
            if (!in_array($cmd, $allowed)) {
                return $this->error("Command not allowed: {$cmd}");
            }
        }

        // Check max processes
        $maxProcesses = (int)($this->config['max_processes'] ?? 20);
        $running = $this->getRunningProcesses($stateDir);
        if (count($running) >= $maxProcesses) {
            return $this->error("Maximum processes reached ({$maxProcesses}). Stop some first.");
        }

        // Create output files
        $id = 'proc_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $stdoutFile = "{$stateDir}/{$id}.stdout.log";
        $stderrFile = "{$stateDir}/{$id}.stderr.log";

        // Start process in background
        $fullCmd = sprintf(
            'nohup %s > %s 2> %s & echo $!',
            $command,
            escapeshellarg($stdoutFile),
            escapeshellarg($stderrFile)
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($fullCmd, $descriptors, $pipes, $cwd, null);
        if (!is_resource($process)) {
            return $this->error("Failed to start process");
        }

        fclose($pipes[0]);
        $pid = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $pid = (int)$pid;
        if ($pid <= 0) {
            return $this->error("Failed to get process PID");
        }

        // Save state
        $state = [
            'id' => $id,
            'pid' => $pid,
            'name' => $name,
            'command' => $command,
            'cwd' => $cwd,
            'started_at' => date('c'),
            'stdout_file' => $stdoutFile,
            'stderr_file' => $stderrFile,
        ];

        file_put_contents("{$stateDir}/{$id}.json", json_encode($state, JSON_PRETTY_PRINT));

        return $this->success([
            'id' => $id,
            'pid' => $pid,
            'name' => $name,
            'command' => $command,
            'stdout_file' => $stdoutFile,
            'stderr_file' => $stderrFile,
        ], "Process started: {$name} (PID {$pid})");
    }

    private function stopProcess(array $args, string $stateDir): array
    {
        $pid = $args['pid'] ?? null;
        $id = null;

        // Find by PID or by ID
        if (!$pid) {
            return $this->error("Provide a 'pid' to stop");
        }

        if (!$this->isProcessRunning($pid)) {
            return $this->success(['pid' => $pid, 'stopped' => true], "Process already stopped");
        }

        // Send SIGTERM first, then SIGKILL if needed
        posix_kill($pid, SIGTERM);
        usleep(500000); // 500ms grace period

        if ($this->isProcessRunning($pid)) {
            posix_kill($pid, SIGKILL);
        }

        // Update state file
        $this->updateStateFile($stateDir, $pid, 'stopped');

        return $this->success([
            'pid' => $pid,
            'stopped' => true,
        ]);
    }

    private function processStatus(array $args, string $stateDir): array
    {
        $pid = $args['pid'] ?? null;
        if (!$pid) {
            return $this->error("Provide a 'pid' to check");
        }

        $running = $this->isProcessRunning($pid);
        $stateFile = $this->findStateByPid($stateDir, $pid);
        $state = $stateFile ? json_decode(file_get_contents($stateFile), true) : null;

        $result = [
            'pid' => $pid,
            'running' => $running,
        ];

        if ($state) {
            $result['name'] = $state['name'] ?? null;
            $result['command'] = $state['command'] ?? null;
            $result['started_at'] = $state['started_at'] ?? null;
        }

        return $this->success($result);
    }

    private function listProcesses(string $stateDir): array
    {
        $processes = [];
        foreach (glob("{$stateDir}/*.json") as $file) {
            $state = json_decode(file_get_contents($file), true);
            if (!$state || !isset($state['pid'])) continue;

            $running = $this->isProcessRunning($state['pid']);
            $processes[] = [
                'id' => $state['id'] ?? basename($file, '.json'),
                'pid' => $state['pid'],
                'name' => $state['name'] ?? 'unknown',
                'command' => $state['command'] ?? '',
                'running' => $running,
                'started_at' => $state['started_at'] ?? null,
            ];
        }

        return $this->success([
            'processes' => $processes,
            'total' => count($processes),
            'running' => count(array_filter($processes, fn($p) => $p['running'])),
        ]);
    }

    private function tailProcess(array $args, string $stateDir): array
    {
        $pid = $args['pid'] ?? null;
        if (!$pid) {
            return $this->error("Provide a 'pid' to tail");
        }

        $lines = (int)($args['tail_lines'] ?? 50);
        $stateFile = $this->findStateByPid($stateDir, $pid);

        if (!$stateFile) {
            return $this->error("No state file found for PID {$pid}");
        }

        $state = json_decode(file_get_contents($stateFile), true);
        $stdout = '';
        $stderr = '';

        if (isset($state['stdout_file']) && file_exists($state['stdout_file'])) {
            $allLines = file($state['stdout_file'], FILE_IGNORE_NEW_LINES);
            $stdout = implode("\n", array_slice($allLines ?: [], -$lines));
        }

        if (isset($state['stderr_file']) && file_exists($state['stderr_file'])) {
            $allLines = file($state['stderr_file'], FILE_IGNORE_NEW_LINES);
            $stderr = implode("\n", array_slice($allLines ?: [], -$lines));
        }

        return $this->success([
            'pid' => $pid,
            'name' => $state['name'] ?? 'unknown',
            'running' => $this->isProcessRunning($pid),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ]);
    }

    private function killAll(string $stateDir): array
    {
        $killed = 0;
        foreach (glob("{$stateDir}/*.json") as $file) {
            $state = json_decode(file_get_contents($file), true);
            if (!$state || !isset($state['pid'])) continue;

            if ($this->isProcessRunning($state['pid'])) {
                posix_kill($state['pid'], SIGTERM);
                $killed++;
            }
        }

        return $this->success([
            'killed' => $killed,
        ], "Sent SIGTERM to {$killed} processes");
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) return false;
        // posix_kill with signal 0 checks if process exists
        return function_exists('posix_kill') ? posix_kill($pid, 0) : file_exists("/proc/{$pid}");
    }

    private function getRunningProcesses(string $stateDir): array
    {
        $running = [];
        foreach (glob("{$stateDir}/*.json") as $file) {
            $state = json_decode(file_get_contents($file), true);
            if ($state && isset($state['pid']) && $this->isProcessRunning($state['pid'])) {
                $running[] = $state;
            }
        }
        return $running;
    }

    private function findStateByPid(string $stateDir, int $pid): ?string
    {
        foreach (glob("{$stateDir}/*.json") as $file) {
            $state = json_decode(file_get_contents($file), true);
            if ($state && ($state['pid'] ?? 0) === $pid) {
                return $file;
            }
        }
        return null;
    }

    private function updateStateFile(string $stateDir, int $pid, string $status): void
    {
        $file = $this->findStateByPid($stateDir, $pid);
        if ($file) {
            $state = json_decode(file_get_contents($file), true);
            $state['status'] = $status;
            $state['stopped_at'] = date('c');
            file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
        }
    }
}
