<?php

namespace App\Libraries\Providers;

/**
 * Provider adapter for Claude via Anthropic API.
 * Supports both API key and OAuth authentication.
 * This is separate from ClaudeCodeProvider which uses the local CLI.
 *
 * Auth priority: OAuth token -> API key from env -> API key from config
 *
 * To enable OAuth, add to providers.json:
 *   "claude_api": {
 *     "oauth": {
 *       "enabled": true,
 *       "client_id": "your-client-id",
 *       "client_secret": "your-client-secret"
 *     }
 *   }
 *
 * Then run: php spark agent:auth login claude_api
 */
class ClaudeAPIProvider extends BaseProvider
{
    protected string $name = 'claude_api';
    protected string $description = 'Claude via Anthropic API (key or OAuth)';

    protected function getDefaultConfig(): array
    {
        return [
            'base_url' => 'https://api.anthropic.com',
            'api_key_env' => 'ANTHROPIC_API_KEY',
            'default_model' => 'claude-sonnet-4-20250514',
            'api_version' => '2023-06-01',
            'max_tokens' => 4096,
            'timeout' => 180,
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
            'model_list' => false,
            'oauth' => true,
        ];
    }

    public function healthCheck(): array
    {
        $token = $this->resolveToken();
        $authMethod = $this->getAuthMethod();

        if (!$token) {
            return ['status' => 'error', 'provider' => $this->name, 'message' => 'No credentials configured (API key or OAuth)', 'auth_method' => 'none'];
        }

        // Send a minimal request to check auth
        $headers = $this->buildHeaders($token);
        $headers[] = 'Content-Type: application/json';

        $result = $this->httpRequest(
            'POST',
            rtrim($this->config['base_url'], '/') . '/v1/messages',
            $headers,
            [
                'model' => $this->config['default_model'],
                'max_tokens' => 1,
                'messages' => [['role' => 'user', 'content' => 'hi']],
            ],
            10
        );

        // 200 or 400 (bad request but auth worked) = healthy
        $httpCode = $result['http_code'] ?? 0;
        if ($httpCode >= 200 && $httpCode < 500 && $httpCode !== 401 && $httpCode !== 403) {
            return ['status' => 'ok', 'provider' => $this->name, 'auth_method' => $authMethod];
        }

        return ['status' => 'error', 'provider' => $this->name, 'message' => $result['error'] ?? "HTTP {$httpCode}", 'auth_method' => $authMethod];
    }

    public function listModels(): array
    {
        return [
            ['name' => 'claude-sonnet-4-20250514', 'description' => 'Claude Sonnet 4'],
            ['name' => 'claude-opus-4-20250514', 'description' => 'Claude Opus 4'],
            ['name' => 'claude-haiku-4-5-20251001', 'description' => 'Claude Haiku 4.5'],
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        $token = $this->resolveToken();
        if (!$token) {
            return $this->errorResponse('No credentials configured for Claude API (API key or OAuth)');
        }

        $model = $options['model'] ?? $this->config['default_model'];
        $maxTokens = $options['max_tokens'] ?? $this->config['max_tokens'];

        // Extract system prompt (Anthropic API uses a separate system field)
        $systemPrompt = null;
        $chatMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt = ($systemPrompt ? $systemPrompt . "\n" : '') . $msg['content'];
            } else {
                $chatMessages[] = $msg;
            }
        }

        // Ensure messages alternate correctly (Anthropic requires user/assistant alternation)
        if (empty($chatMessages)) {
            return $this->errorResponse('No user messages provided');
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $chatMessages,
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $headers = $this->buildHeaders($token);
        $headers[] = 'Content-Type: application/json';

        $result = $this->httpRequest(
            'POST',
            rtrim($this->config['base_url'], '/') . '/v1/messages',
            $headers,
            $payload,
            $this->config['timeout']
        );

        if (!$result['success']) {
            if (($result['http_code'] ?? 0) === 401 && $this->getAuthMethod() === 'oauth') {
                return $this->errorResponse('OAuth token expired. Run: php spark agent:auth login claude_api', 401);
            }
            $errorMsg = $result['decoded']['error']['message'] ?? $result['error'] ?? 'Claude API request failed';
            return $this->errorResponse($errorMsg, $result['http_code'] ?? 0);
        }

        $data = $result['decoded'] ?? [];
        $content = '';
        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            }
        }

        return $this->successResponse($content, [
            'model' => $data['model'] ?? $model,
            'usage' => [
                'input_tokens' => $data['usage']['input_tokens'] ?? null,
                'output_tokens' => $data['usage']['output_tokens'] ?? null,
            ],
            'stop_reason' => $data['stop_reason'] ?? null,
            'auth_method' => $this->getAuthMethod(),
        ]);
    }

    /**
     * Build auth headers. OAuth uses Authorization Bearer, API key uses x-api-key.
     */
    private function buildHeaders(string $token): array
    {
        $headers = [
            'anthropic-version: ' . $this->config['api_version'],
        ];

        if ($this->getAuthMethod() === 'oauth') {
            $headers[] = 'Authorization: Bearer ' . $token;
        } else {
            $headers[] = 'x-api-key: ' . $token;
        }

        return $headers;
    }
}
