<?php

namespace App\Libraries\Tools;

class ShellExecTool extends BaseTool
{
    protected string $name = 'shell_exec';
    protected string $description = 'Execute a shell command';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 60,
            'allowed_commands' => [], // empty = allow all
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'command' => ['type' => 'string', 'required' => true],
            'cwd' => ['type' => 'string', 'required' => false],
            'timeout' => ['type' => 'int', 'required' => false, 'default' => 60],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['command'])) return $err;

        $allowed = $this->config['allowed_commands'] ?? [];
        if (!empty($allowed)) {
            $cmd = explode(' ', trim($args['command']))[0];
            if (!in_array($cmd, $allowed)) {
                return $this->error("Command not in allowed list: {$cmd}");
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $args['cwd'] ?? getcwd();
        $process = proc_open($args['command'], $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            return $this->error('Failed to start process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return $this->success([
            'command' => $args['command'],
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ]);
    }
}
