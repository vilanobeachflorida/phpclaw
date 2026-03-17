<?php

namespace App\Libraries\Providers;

/**
 * Example provider demonstrating the provider template pattern.
 * Returns echo responses for testing without a real LLM backend.
 */
class ExampleProvider extends BaseProvider
{
    protected string $name = 'example';
    protected string $description = 'Example echo provider for testing';

    protected function getDefaultConfig(): array
    {
        return [
            'echo_prefix' => '[Echo]',
            'default_model' => 'echo-v1',
        ];
    }

    public function healthCheck(): array
    {
        return ['status' => 'ok', 'provider' => $this->name, 'message' => 'Example provider always healthy'];
    }

    public function listModels(): array
    {
        return [
            ['name' => 'echo-v1', 'description' => 'Simple echo model'],
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        $lastMessage = end($messages);
        $content = $lastMessage['content'] ?? '';
        $prefix = $this->config['echo_prefix'] ?? '[Echo]';

        return $this->successResponse("{$prefix} {$content}", [
            'model' => 'echo-v1',
        ]);
    }
}
