<?php

namespace App\Libraries\Tools;

class BrowserFetchTool extends BaseTool
{
    protected string $name = 'browser_fetch';
    protected string $description = 'Fetch web page content';

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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("Fetch failed: {$error}");
        }

        return $this->success([
            'url' => $args['url'],
            'http_code' => $httpCode,
            'html' => $html,
            'size' => strlen($html),
        ]);
    }
}
