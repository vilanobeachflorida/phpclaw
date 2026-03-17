<?php

namespace App\Libraries\Tools;

/**
 * Lightweight text extraction from HTML content.
 * Strips tags and returns readable text.
 */
class BrowserTextTool extends BaseTool
{
    protected string $name = 'browser_text';
    protected string $description = 'Extract readable text from web page';

    public function getInputSchema(): array
    {
        return [
            'url' => ['type' => 'string', 'required' => true],
            'timeout' => ['type' => 'int', 'required' => false, 'default' => 30],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['url'])) return $err;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $args['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => (int)($args['timeout'] ?? 30),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PHPClaw/0.1)',
        ]);

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
            'url' => $args['url'],
            'text' => $text,
            'length' => strlen($text),
        ]);
    }
}
