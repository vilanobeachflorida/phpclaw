<?php

namespace App\Libraries\Providers;

/**
 * Provider adapter for Claude Code local CLI runtime.
 * Invokes the claude CLI tool and captures output.
 */
class ClaudeCodeProvider extends BaseProvider
{
    protected string $name = 'claude_code';
    protected string $description = 'Claude Code local CLI runtime';

    protected function getDefaultConfig(): array
    {
        return [
            'command' => 'claude',
            'timeout' => 180,
            'options' => [],
        ];
    }

    public function getCapabilities(): array
    {
        return [
            'chat' => true,
            'streaming' => false,
            'system_prompt' => true,
            'model_list' => false,
        ];
    }

    public function healthCheck(): array
    {
        $cmd = $this->config['command'] . ' --version 2>&1';
        $output = shell_exec($cmd);
        if ($output !== null && trim($output) !== '') {
            return ['status' => 'ok', 'provider' => $this->name, 'version' => trim($output)];
        }
        return ['status' => 'error', 'provider' => $this->name, 'message' => 'Claude Code CLI not found'];
    }

    public function listModels(): array
    {
        return [['name' => 'default', 'description' => 'Claude Code default model']];
    }

    public function chat(array $messages, array $options = []): array
    {
        // Build prompt from messages
        $prompt = '';
        $systemPrompt = '';
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt .= $msg['content'] . "\n";
            } else {
                $prompt .= $msg['content'] . "\n";
            }
        }

        $prompt = trim($prompt);
        if (empty($prompt)) {
            return $this->errorResponse('Empty prompt');
        }

        // Use claude CLI with --print flag for non-interactive mode
        $cmd = $this->config['command'] . ' --print';
        if ($systemPrompt) {
            $escapedSystem = escapeshellarg(trim($systemPrompt));
            $cmd .= ' --system-prompt ' . $escapedSystem;
        }
        $cmd .= ' ' . escapeshellarg($prompt);
        $cmd .= ' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return $this->errorResponse('Failed to start Claude Code process');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return $this->errorResponse('Claude Code exited with code ' . $exitCode . ': ' . trim($stderr), $exitCode);
        }

        return $this->successResponse(trim($stdout), [
            'model' => 'claude-code',
        ]);
    }
}
