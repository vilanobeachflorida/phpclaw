<?php

namespace App\Libraries\Providers;

/**
 * Provider adapter for OpenAI-compatible LLM HTTP endpoints.
 * Works with vLLM, text-generation-inference, LocalAI, LiteLLM, etc.
 */
class OpenLLMProvider extends BaseProvider
{
    protected string $name = 'openllm';
    protected string $description = 'OpenAI-compatible LLM endpoint';

    protected function getDefaultConfig(): array
    {
        return [
            'base_url' => 'http://localhost:8000',
            'api_key_env' => 'OPENLLM_API_KEY',
            'default_model' => 'default',
            'timeout' => 120,
            'headers' => [],
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
        $result = $this->httpRequest('GET', rtrim($this->config['base_url'], '/') . '/v1/models', $this->buildHeaders(), null, 5);
        if ($result['success']) {
            return ['status' => 'ok', 'provider' => $this->name];
        }
        return ['status' => 'error', 'provider' => $this->name, 'message' => $result['error'] ?? 'Connection failed'];
    }

    public function listModels(): array
    {
        $result = $this->httpRequest('GET', rtrim($this->config['base_url'], '/') . '/v1/models', $this->buildHeaders());
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
        $model = $options['model'] ?? $this->config['default_model'];
        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], $options['model_options'] ?? []);

        $result = $this->httpRequest(
            'POST',
            rtrim($this->config['base_url'], '/') . '/v1/chat/completions',
            $this->buildHeaders(['Content-Type: application/json']),
            $payload,
            $this->config['timeout']
        );

        if (!$result['success']) {
            return $this->errorResponse($result['error'] ?? 'OpenLLM request failed', $result['http_code'] ?? 0);
        }

        $data = $result['decoded'] ?? [];
        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->successResponse($content, [
            'model' => $data['model'] ?? $model,
            'usage' => $data['usage'] ?? null,
        ]);
    }

    private function buildHeaders(array $extra = []): array
    {
        $headers = $extra;
        $apiKey = $this->resolveApiKey();
        if ($apiKey) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        foreach (($this->config['headers'] ?? []) as $key => $value) {
            $headers[] = "$key: $value";
        }
        return $headers;
    }
}
