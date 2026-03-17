<?php

namespace App\Libraries\Providers;

/**
 * Provider adapter for local Ollama instance.
 * Communicates via Ollama's HTTP API on localhost.
 */
class OllamaProvider extends BaseProvider
{
    protected string $name = 'ollama';
    protected string $description = 'Local Ollama LLM instance';

    protected function getDefaultConfig(): array
    {
        return [
            'base_url' => 'http://localhost:11434',
            'default_model' => 'llama3',
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
        $result = $this->httpRequest('GET', $this->config['base_url'] . '/api/tags', [], null, 5);
        if ($result['success']) {
            return ['status' => 'ok', 'provider' => $this->name, 'message' => 'Ollama is reachable'];
        }
        return ['status' => 'error', 'provider' => $this->name, 'message' => $result['error'] ?? 'Connection failed'];
    }

    public function listModels(): array
    {
        $result = $this->httpRequest('GET', $this->config['base_url'] . '/api/tags');
        if (!$result['success'] || !isset($result['decoded']['models'])) {
            return [];
        }
        return array_map(fn($m) => [
            'name' => $m['name'],
            'size' => $m['size'] ?? null,
            'modified' => $m['modified_at'] ?? null,
        ], $result['decoded']['models']);
    }

    public function chat(array $messages, array $options = []): array
    {
        $model = $options['model'] ?? $this->config['default_model'];
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => array_merge($this->config['options'] ?? [], $options['model_options'] ?? []),
        ];

        $result = $this->httpRequest(
            'POST',
            $this->config['base_url'] . '/api/chat',
            ['Content-Type: application/json'],
            $payload,
            $this->config['timeout']
        );

        if (!$result['success']) {
            return $this->errorResponse($result['error'] ?? 'Ollama request failed', $result['http_code'] ?? 0);
        }

        $data = $result['decoded'] ?? [];
        $content = $data['message']['content'] ?? '';

        return $this->successResponse($content, [
            'model' => $data['model'] ?? $model,
            'usage' => [
                'prompt_tokens' => $data['prompt_eval_count'] ?? null,
                'completion_tokens' => $data['eval_count'] ?? null,
                'total_duration' => $data['total_duration'] ?? null,
            ],
        ]);
    }
}
