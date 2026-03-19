<?php

namespace App\Libraries\Tools;

/**
 * Full HTTP client supporting all methods, custom headers, bodies, auth,
 * and persistent cookie/session management with browser spoofing.
 */
class HttpRequestTool extends BaseTool
{
    protected string $name = 'http_request';
    protected string $description = 'Make HTTP requests with any method (GET, POST, PUT, PATCH, DELETE), form/JSON body, cookies, and session persistence. Use the same session name across requests to maintain login state.';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 30,
            'max_body_size' => 10485760, // 10MB
            'blocked_hosts' => [],
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
                'description' => 'Form data — sent as application/x-www-form-urlencoded (use this for login forms)',
            ],
            'session' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Session name for cookie persistence (default: "default"). Use the same session name across requests to maintain login state, CSRF tokens, and authenticated sessions.',
            ],
            'referer' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Referer URL — set this to the page that contained the form for more realistic requests',
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

        $session = CurlSession::defaultSessionForUrl($args['url'], $args['session'] ?? null);
        $url = CurlSession::normalizeUrl($args['url'], $session);
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

        // Build extra headers from user-supplied key-value pairs
        $extraHeaders = [];
        $rawHeaders = $args['headers'] ?? [];
        foreach ($rawHeaders as $key => $value) {
            $extraHeaders[] = "{$key}: {$value}";
        }

        // Determine body and content type
        $body = null;
        if (isset($args['json'])) {
            $body = is_string($args['json']) ? $args['json'] : json_encode($args['json']);
            $extraHeaders[] = 'Content-Type: application/json';
        } elseif (isset($args['form'])) {
            $body = http_build_query($args['form']);
            $extraHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($args['body'])) {
            $body = $args['body'];
        }

        // Set referer for form submissions
        if (isset($args['referer'])) {
            $extraHeaders[] = 'Referer: ' . $args['referer'];
        }

        // For POST form submissions, mimic browser fetch headers
        if ($method === 'POST' && isset($args['form'])) {
            $extraHeaders[] = 'Sec-Fetch-Site: same-origin';
            $extraHeaders[] = 'Sec-Fetch-Mode: navigate';
            $extraHeaders[] = 'Sec-Fetch-Dest: document';
            $extraHeaders[] = 'Origin: ' . parse_url($url, PHP_URL_SCHEME) . '://' . $host;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADER         => true,
        ]);

        if (!$followRedirects) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        }

        CurlSession::applyBrowserOptions($ch, $session, $extraHeaders);

        // Use CURLOPT_POST for POST requests instead of CURLOPT_CUSTOMREQUEST.
        // CURLOPT_CUSTOMREQUEST('POST') persists the POST method on 303 redirects,
        // causing servers to return 403. CURLOPT_POST properly switches to GET
        // on 303 redirects, matching real browser behavior.
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
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
            'effective_url' => $effectiveUrl,
            'method' => $method,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'response_headers' => $parsedHeaders,
            'body' => $jsonBody ?? $responseBody,
            'body_is_json' => $jsonBody !== null,
            'size' => strlen($responseBody),
            'time_seconds' => round($totalTime, 3),
            'session' => $session,
        ]);
    }
}
