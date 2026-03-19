<?php

namespace App\Libraries\Tools;

class ShellExecTool extends BaseTool
{
    protected string $name = 'shell_exec';
    protected string $description = 'Execute a shell command';

    /** Max bytes to read from stdout/stderr to prevent OOM. */
    private const MAX_OUTPUT_BYTES = 10_485_760; // 10MB

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 60,
            'allowed_commands' => [], // empty = allow all
            'max_output_bytes' => self::MAX_OUTPUT_BYTES,
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

        $timeout = (int)($args['timeout'] ?? $this->config['timeout'] ?? 60);
        $maxBytes = $this->config['max_output_bytes'] ?? self::MAX_OUTPUT_BYTES;

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

        // Set non-blocking so we can enforce timeout and size limits
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $startTime = microtime(true);

        while (true) {
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $timeout) {
                // Kill the process on timeout
                proc_terminate($process, 9);
                break;
            }

            $status = proc_get_status($process);

            // Read stdout (capped)
            if (strlen($stdout) < $maxBytes) {
                $chunk = @fread($pipes[1], min(65536, $maxBytes - strlen($stdout)));
                if ($chunk !== false && $chunk !== '') {
                    $stdout .= $chunk;
                    if (strlen($stdout) >= $maxBytes) $stdoutTruncated = true;
                }
            }

            // Read stderr (capped)
            if (strlen($stderr) < $maxBytes) {
                $chunk = @fread($pipes[2], min(65536, $maxBytes - strlen($stderr)));
                if ($chunk !== false && $chunk !== '') {
                    $stderr .= $chunk;
                    if (strlen($stderr) >= $maxBytes) $stderrTruncated = true;
                }
            }

            // Process exited and pipes drained
            if (!$status['running']) {
                // Do a final read to drain any remaining buffered output
                $finalOut = @stream_get_contents($pipes[1], $maxBytes - strlen($stdout));
                if ($finalOut) $stdout .= $finalOut;
                $finalErr = @stream_get_contents($pipes[2], $maxBytes - strlen($stderr));
                if ($finalErr) $stderr .= $finalErr;
                break;
            }

            // Brief sleep to avoid busy loop
            usleep(10000); // 10ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($stdoutTruncated) {
            $stdout .= "\n... (truncated at {$maxBytes} bytes)";
        }
        if ($stderrTruncated) {
            $stderr .= "\n... (truncated at {$maxBytes} bytes)";
        }

        $timedOut = (microtime(true) - $startTime) >= $timeout;

        return $this->success([
            'command' => $args['command'],
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => $timedOut,
        ]);
    }
}
