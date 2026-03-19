<?php

namespace App\Libraries\Tools;

class HttpGetTool extends BaseTool
{
    protected string $name = 'http_get';
    protected string $description = 'Make HTTP GET request with browser-like session and cookie support';

    public function getInputSchema(): array
    {
        return [
            'url' => ['type' => 'string', 'required' => true, 'description' => 'Target URL'],
            'headers' => ['type' => 'array', 'required' => false, 'description' => 'Additional headers'],
            'session' => ['type' => 'string', 'required' => false, 'description' => 'Session name for cookie persistence (default: "default"). Use the same session name across requests to maintain login state.'],
            'timeout' => ['type' => 'int', 'required' => false, 'default' => 30],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['url'])) return $err;

        $session = CurlSession::defaultSessionForUrl($args['url'], $args['session'] ?? null);
        $url = CurlSession::normalizeUrl($args['url'], $session);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)($args['timeout'] ?? 30),
        ]);

        CurlSession::applyBrowserOptions($ch, $session, $args['headers'] ?? []);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("HTTP request failed: {$error}");
        }

        return $this->success([
            'url' => $url,
            'effective_url' => $effectiveUrl,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'body' => $response,
            'size' => strlen($response),
            'session' => $session,
        ]);
    }
}
