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
        ];

        // Merge any model-specific options (temperature, max_tokens, etc.)
        if (!empty($options['model_options'])) {
            $payload = array_merge($payload, $options['model_options']);
        }

        // Use the higher of provider timeout and role timeout
        $timeout = max($this->config['timeout'], $options['timeout'] ?? 0);

        // Use streaming when enabled by the router (provider supports it + progress callback set)
        // Short calls (routing, classify) don't get streaming
        $useStream = ($options['stream'] ?? false) && $this->progressCallback;
        if ($useStream) {
            $payload['stream'] = true;
            return $this->streamingChat($payload, $timeout);
        }

        // Normal non-streaming call
        $payload['stream'] = false;
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

    /**
     * Streaming chat — reads SSE chunks so curl progress fires regularly
     * and the UI timer stays accurate.
     */
    private function streamingChat(array $payload, int $timeout): array
    {
        $url = rtrim($this->config['base_url'], '/') . '/v1/chat/completions';
        $content = '';
        $model = $payload['model'] ?? 'unknown';
        $finishReason = null;
        $usage = null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        // Enable progress callback for timer updates
        if ($this->progressCallback) {
            $startTime = microtime(true);
            $callback = $this->progressCallback;
            $lastUpdate = 0;
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
                function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($startTime, $callback, &$lastUpdate) {
                    $elapsed = microtime(true) - $startTime;
                    if ($elapsed - $lastUpdate >= 3.0) {
                        $lastUpdate = $elapsed;
                        $callback($elapsed, (int)$dlNow);
                    }
                    return 0;
                }
            );
        }

        // Process SSE chunks as they arrive
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$content, &$model, &$finishReason, &$usage) {
            // SSE format: "data: {json}\n\n"
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line === 'data: [DONE]') continue;
                if (!str_starts_with($line, 'data: ')) continue;

                $json = json_decode(substr($line, 6), true);
                if (!$json) continue;

                // Extract delta content
                $delta = $json['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $content .= $delta;
                }

                // Capture model name
                if (isset($json['model'])) {
                    $model = $json['model'];
                }

                // Capture finish reason
                if (isset($json['choices'][0]['finish_reason']) && $json['choices'][0]['finish_reason']) {
                    $finishReason = $json['choices'][0]['finish_reason'];
                }

                // Some providers send usage in the final chunk
                if (isset($json['usage'])) {
                    $usage = $json['usage'];
                }
            }

            return strlen($data); // Must return bytes consumed
        });

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->errorResponse("LM Studio request failed: {$error}", 0);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return $this->errorResponse("LM Studio HTTP {$httpCode}", $httpCode);
        }

        // Estimate usage if not provided (common with streaming)
        if (!$usage) {
            $usage = [
                'prompt_tokens' => null,
                'completion_tokens' => null,
                'total_tokens' => null,
            ];
        }

        return $this->successResponse($content, [
            'model' => $model,
            'usage' => $usage,
            'finish_reason' => $finishReason,
        ]);
    }
}
