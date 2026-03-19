<?php

namespace App\Libraries\Tools;

class BrowserFetchTool extends BaseTool
{
    protected string $name = 'browser_fetch';
    protected string $description = 'Fetch web page content with full browser spoofing, cookies, and session persistence';

    public function getInputSchema(): array
    {
        return [
            'url' => ['type' => 'string', 'required' => true, 'description' => 'Target URL'],
            'session' => ['type' => 'string', 'required' => false, 'description' => 'Session name for cookie persistence (default: "default")'],
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

        CurlSession::applyBrowserOptions($ch, $session);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("Fetch failed: {$error}");
        }

        return $this->success([
            'url' => $url,
            'effective_url' => $effectiveUrl,
            'http_code' => $httpCode,
            'html' => $html,
            'size' => strlen($html),
            'session' => $session,
        ]);
    }
}
