<?php

namespace App\Libraries\Providers;

/**
 * Provider adapter for ChatGPT / OpenAI API.
 * Supports injected API keys or tokens.
 * For OAuth-based access, supply credentials externally and configure via providers.json.
 */
class ChatGPTProvider extends BaseProvider
{
    protected string $name = 'chatgpt';
    protected string $description = 'ChatGPT / OpenAI API';

    protected function getDefaultConfig(): array
    {
        return [
            'base_url' => 'https://api.openai.com/v1',
            'api_key_env' => 'OPENAI_API_KEY',
            'default_model' => 'gpt-4',
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
        $apiKey = $this->resolveApiKey();
        if (!$apiKey) {
            return ['status' => 'error', 'provider' => $this->name, 'message' => 'No API key configured'];
        }

        $result = $this->httpRequest('GET', rtrim($this->config['base_url'], '/') . '/models', [
            'Authorization: Bearer ' . $apiKey,
        ], null, 10);

        if ($result['success']) {
            return ['status' => 'ok', 'provider' => $this->name];
        }
        return ['status' => 'error', 'provider' => $this->name, 'message' => $result['error'] ?? 'Connection failed'];
    }

    public function listModels(): array
    {
        $apiKey = $this->resolveApiKey();
        if (!$apiKey) {
            return [];
        }

        $result = $this->httpRequest('GET', rtrim($this->config['base_url'], '/') . '/models', [
            'Authorization: Bearer ' . $apiKey,
        ]);

        if (!$result['success'] || !isset($result['decoded']['data'])) {
            return [];
        }

        return array_map(fn($m) => [
            'name' => $m['id'] ?? 'unknown',
            'owned_by' => $m['owned_by'] ?? null,
        ], $result['decoded']['data']);
    }

    public function chat(array $messages, array $options = []): array
    {
        $apiKey = $this->resolveApiKey();
        if (!$apiKey) {
            return $this->errorResponse('No API key configured for ChatGPT');
        }

        $model = $options['model'] ?? $this->config['default_model'];
        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], $options['model_options'] ?? []);

        $result = $this->httpRequest(
            'POST',
            rtrim($this->config['base_url'], '/') . '/chat/completions',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            $payload,
            $this->config['timeout']
        );

        if (!$result['success']) {
            return $this->errorResponse($result['error'] ?? 'ChatGPT request failed', $result['http_code'] ?? 0);
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
