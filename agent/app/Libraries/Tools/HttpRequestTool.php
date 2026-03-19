<?php

namespace App\Libraries\Tools;

/**
 * Full HTTP client supporting all methods, custom headers, bodies, and auth.
 * Extends beyond http_get to enable API integrations and webhook calls.
 */
class HttpRequestTool extends BaseTool
{
    protected string $name = 'http_request';
    protected string $description = 'Make HTTP requests with any method (GET, POST, PUT, PATCH, DELETE), custom headers, body, and auth';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 30,
            'max_body_size' => 10485760, // 10MB
            'blocked_hosts' => [],       // hosts that cannot be reached
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Request URL',
            ],
            'method' => [
                'type' => 'string',
                'required' => false,
                'description' => 'HTTP method: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS (default: GET)',
            ],
            'headers' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Request headers as key-value pairs (e.g. {"Authorization": "Bearer token"})',
            ],
            'body' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Request body (string or JSON string)',
            ],
            'json' => [
                'type' => 'object',
                'required' => false,
                'description' => 'JSON body — automatically sets Content-Type and encodes the object',
            ],
            'form' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Form data — sent as application/x-www-form-urlencoded',
            ],
            'timeout' => [
                'type' => 'int',
                'required' => false,
                'description' => 'Request timeout in seconds (default: 30)',
            ],
            'follow_redirects' => [
                'type' => 'bool',
                'required' => false,
                'description' => 'Follow HTTP redirects (default: true)',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['url'])) return $err;

        $url = $args['url'];
        $method = strtoupper($args['method'] ?? 'GET');
        $timeout = (int)($args['timeout'] ?? ($this->config['timeout'] ?? 30));
        $followRedirects = (bool)($args['follow_redirects'] ?? true);

        $validMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
        if (!in_array($method, $validMethods)) {
            return $this->error("Invalid HTTP method: {$method}. Valid: " . implode(', ', $validMethods));
        }

        // Check blocked hosts
        $host = parse_url($url, PHP_URL_HOST);
        $blockedHosts = $this->config['blocked_hosts'] ?? [];
        if (in_array($host, $blockedHosts)) {
            return $this->error("Host is blocked: {$host}");
        }

        // Build headers
        $headers = [];
        $rawHeaders = $args['headers'] ?? [];
        foreach ($rawHeaders as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        // Determine body
        $body = null;
        if (isset($args['json'])) {
            $body = is_string($args['json']) ? $args['json'] : json_encode($args['json']);
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($args['form'])) {
            $body = http_build_query($args['form']);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($args['body'])) {
            $body = $args['body'];
        }

        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'PHPClaw/0.1',
            CURLOPT_HEADER => true,
        ];

        if ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("HTTP request failed: {$error}");
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        // Parse response headers
        $parsedHeaders = [];
        foreach (explode("\r\n", trim($responseHeaders)) as $headerLine) {
            if (str_contains($headerLine, ':')) {
                [$key, $value] = explode(':', $headerLine, 2);
                $parsedHeaders[trim($key)] = trim($value);
            }
        }

        // Try to parse JSON response
        $jsonBody = null;
        if ($contentType && str_contains($contentType, 'json')) {
            $jsonBody = json_decode($responseBody, true);
        }

        return $this->success([
            'url' => $url,
            'method' => $method,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'response_headers' => $parsedHeaders,
            'body' => $jsonBody ?? $responseBody,
            'body_is_json' => $jsonBody !== null,
            'size' => strlen($responseBody),
            'time_seconds' => round($totalTime, 3),
        ]);
    }
}
