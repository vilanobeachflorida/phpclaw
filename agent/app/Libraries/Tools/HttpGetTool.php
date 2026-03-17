<?php

namespace App\Libraries\Tools;

class HttpGetTool extends BaseTool
{
    protected string $name = 'http_get';
    protected string $description = 'Make HTTP GET request';

    public function getInputSchema(): array
    {
        return [
            'url' => ['type' => 'string', 'required' => true],
            'headers' => ['type' => 'array', 'required' => false],
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
            CURLOPT_HTTPHEADER => $args['headers'] ?? [],
            CURLOPT_USERAGENT => 'PHPClaw/0.1',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("HTTP request failed: {$error}");
        }

        return $this->success([
            'url' => $args['url'],
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'body' => $response,
            'size' => strlen($response),
        ]);
    }
}
