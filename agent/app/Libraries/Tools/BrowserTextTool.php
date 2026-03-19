<?php

namespace App\Libraries\Tools;

/**
 * Lightweight text extraction from HTML content.
 * Strips tags and returns readable text.
 */
class BrowserTextTool extends BaseTool
{
    protected string $name = 'browser_text';
    protected string $description = 'Extract readable text from web page with browser spoofing and session support';

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
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("Fetch failed: {$error}");
        }

        // Strip scripts and styles, then extract text
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $this->success([
            'url' => $url,
            'text' => $text,
            'length' => strlen($text),
            'session' => $session,
        ]);
    }
}
