<?php

namespace App\Libraries\Providers;

/**
 * Provider adapter for LM Studio.
 * LM Studio runs a local OpenAI-compatible API server, typically on port 1234.
 * Uses /v1/chat/completions endpoint (NOT Ollama's /api/chat).
 */
class LMStudioProvider extends BaseProvider
{
    protected string $name = 'lmstudio';
    protected string $description = 'LM Studio local server';

    protected function getDefaultConfig(): array
    {
        return [
            'base_url' => 'http://localhost:1234',
            'default_model' => 'default',
            'timeout' => 120,
            'options' => [],
        ];
    }

    public function getCapabilities(): array
    {
        return [
            'chat' => true,
            'streaming' => true,
            'system_prompt' => true,
            'model_list' => true,
        ];
    }

    public function healthCheck(): array
    {
        $result = $this->httpRequest('GET', rtrim($this->config['base_url'], '/') . '/v1/models', [], null, 5);
        if ($result['success']) {
            return ['status' => 'ok', 'provider' => $this->name];
        }
        return ['status' => 'error', 'provider' => $this->name, 'message' => $result['error'] ?? 'Connection failed'];
    }

    public function listModels(): array
    {
        $result = $this->httpRequest('GET', rtrim($this->config['base_url'], '/') . '/v1/models');
        if (!$result['success'] || !isset($result['decoded']['data'])) {
            return [];
        }
        return array_map(fn($m) => [
            'name' => $m['id'] ?? 'unknown',
            'owned_by' => $m['owned_by'] ?? 'local',
        ], $result['decoded']['data']);
    }

    public function chat(array $messages, array $options = []): array
    {
        $model = $options['model'] ?? $this->config['default_model'];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ];

        // Merge any model-specific options (temperature, max_tokens, etc.)
        if (!empty($options['model_options'])) {
            $payload = array_merge($payload, $options['model_options']);
        }

        // Use the higher of provider timeout and role timeout
        $timeout = max($this->config['timeout'], $options['timeout'] ?? 0);

        $result = $this->httpRequest(
            'POST',
            rtrim($this->config['base_url'], '/') . '/v1/chat/completions',
            ['Content-Type: application/json'],
            $payload,
            $timeout
        );

        if (!$result['success']) {
            return $this->errorResponse($result['error'] ?? 'LM Studio request failed', $result['http_code'] ?? 0);
        }

        $data = $result['decoded'] ?? [];
        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->successResponse($content, [
            'model' => $data['model'] ?? $model,
            'usage' => $data['usage'] ?? null,
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
        ]);
    }
}
