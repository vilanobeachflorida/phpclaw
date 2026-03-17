<?php

namespace App\Libraries\Providers;

/**
 * Base class for all provider adapters.
 * Provides common config loading, error formatting, and response normalization.
 */
abstract class BaseProvider implements ProviderInterface
{
    protected string $name = '';
    protected string $description = '';
    protected array $config = [];
    protected bool $configured = false;

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function configure(array $config): void
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->configured = true;
    }

    public function isAvailable(): bool
    {
        if (!$this->configured) {
            return false;
        }
        try {
            $health = $this->healthCheck();
            return ($health['status'] ?? 'error') === 'ok';
        } catch (\Throwable $e) {
            return false;
        }
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

    public function send(string $prompt, array $options = []): array
    {
        return $this->chat([['role' => 'user', 'content' => $prompt]], $options);
    }

    protected function getDefaultConfig(): array
    {
        return [];
    }

    /**
     * Build a normalized success response.
     */
    protected function successResponse(string $content, array $meta = []): array
    {
        return [
            'success' => true,
            'content' => $content,
            'provider' => $this->name,
            'model' => $meta['model'] ?? ($this->config['default_model'] ?? 'unknown'),
            'usage' => $meta['usage'] ?? null,
            'timestamp' => date('c'),
            'metadata' => $meta,
        ];
    }

    /**
     * Build a normalized error response.
     */
    protected function errorResponse(string $message, int $code = 0, array $meta = []): array
    {
        return [
            'success' => false,
            'error' => $message,
            'error_code' => $code,
            'provider' => $this->name,
            'timestamp' => date('c'),
            'metadata' => $meta,
        ];
    }

    /**
     * Resolve API key from config or environment variable.
     */
    protected function resolveApiKey(): ?string
    {
        if (!empty($this->config['api_key'])) {
            return $this->config['api_key'];
        }
        $envVar = $this->config['api_key_env'] ?? '';
        if ($envVar && ($key = getenv($envVar))) {
            return $key;
        }
        return null;
    }

    /**
     * Resolve bearer token: try OAuth token first, then fall back to API key.
     */
    protected function resolveToken(): ?string
    {
        // Try OAuth token first
        if (!empty($this->config['oauth']['enabled'])) {
            $oauth = new \App\Libraries\Auth\OAuthManager();
            $token = $oauth->getAccessToken($this->name);
            if ($token) {
                return $token;
            }
        }

        // Fall back to API key
        return $this->resolveApiKey();
    }

    /**
     * Get auth method in use: 'oauth', 'api_key', or 'none'.
     */
    protected function getAuthMethod(): string
    {
        if (!empty($this->config['oauth']['enabled'])) {
            $oauth = new \App\Libraries\Auth\OAuthManager();
            if ($oauth->isValid($this->name)) {
                return 'oauth';
            }
        }
        if ($this->resolveApiKey()) {
            return 'api_key';
        }
        return 'none';
    }

    /**
     * Make an HTTP request using cURL.
     */
    protected function httpRequest(string $method, string $url, array $headers = [], $body = null, int $timeout = 30): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ]);

        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error, 'http_code' => 0, 'body' => null];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body' => $response,
            'decoded' => json_decode($response, true),
        ];
    }
}
