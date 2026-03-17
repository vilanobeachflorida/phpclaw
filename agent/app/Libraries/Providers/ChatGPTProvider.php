<?php

namespace App\Libraries\Providers;

/**
 * Provider adapter for ChatGPT / OpenAI API.
 * Supports both API key and OAuth authentication.
 *
 * Auth priority: OAuth token -> API key from env -> API key from config
 *
 * To enable OAuth, add to providers.json:
 *   "chatgpt": {
 *     "oauth": {
 *       "enabled": true,
 *       "client_id": "your-client-id",
 *       "client_secret": "your-client-secret"
 *     }
 *   }
 *
 * Then run: php spark agent:auth login chatgpt
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
            'oauth' => [
                'enabled' => false,
                'client_id' => '',
                'client_secret' => '',
            ],
        ];
    }

    public function getCapabilities(): array
    {
        return [
            'chat' => true,
            'streaming' => true,
            'system_prompt' => true,
            'model_list' => true,
            'oauth' => true,
        ];
    }

    public function healthCheck(): array
    {
        $token = $this->resolveToken();
        if (!$token) {
            return ['status' => 'error', 'provider' => $this->name, 'message' => 'No credentials configured (API key or OAuth)', 'auth_method' => 'none'];
        }

        $result = $this->httpRequest('GET', rtrim($this->config['base_url'], '/') . '/models', [
            'Authorization: Bearer ' . $token,
        ], null, 10);

        if ($result['success']) {
            return ['status' => 'ok', 'provider' => $this->name, 'auth_method' => $this->getAuthMethod()];
        }
        return ['status' => 'error', 'provider' => $this->name, 'message' => $result['error'] ?? 'Connection failed'];
    }

    public function listModels(): array
    {
        $token = $this->resolveToken();
        if (!$token) {
            return [];
        }

        $result = $this->httpRequest('GET', rtrim($this->config['base_url'], '/') . '/models', [
            'Authorization: Bearer ' . $token,
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
        $token = $this->resolveToken();
        if (!$token) {
            return $this->errorResponse('No credentials configured for ChatGPT (API key or OAuth)');
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
                'Authorization: Bearer ' . $token,
            ],
            $payload,
            $this->config['timeout']
        );

        if (!$result['success']) {
            // If OAuth token expired, hint at re-auth
            if (($result['http_code'] ?? 0) === 401 && $this->getAuthMethod() === 'oauth') {
                return $this->errorResponse('OAuth token expired. Run: php spark agent:auth login chatgpt', 401);
            }
            return $this->errorResponse($result['error'] ?? 'ChatGPT request failed', $result['http_code'] ?? 0);
        }

        $data = $result['decoded'] ?? [];
        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->successResponse($content, [
            'model' => $data['model'] ?? $model,
            'usage' => $data['usage'] ?? null,
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
            'auth_method' => $this->getAuthMethod(),
        ]);
    }
}
