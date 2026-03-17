<?php

namespace App\Libraries\Tools;

/**
 * Base class for all agent tools.
 * Provides shared config loading, result formatting, error handling, and logging hooks.
 */
abstract class BaseTool implements ToolInterface
{
    protected string $name = '';
    protected string $description = '';
    protected array $config = [];
    protected bool $enabled = true;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->enabled = $this->config['enabled'] ?? true;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getInputSchema(): array
    {
        return [];
    }

    protected function getDefaultConfig(): array
    {
        return ['enabled' => true, 'timeout' => 10];
    }

    /**
     * Build a success result payload.
     */
    protected function success($data, string $message = 'OK'): array
    {
        return [
            'success' => true,
            'tool' => $this->name,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c'),
        ];
    }

    /**
     * Build an error result payload.
     */
    protected function error(string $message, int $code = 0, $data = null): array
    {
        return [
            'success' => false,
            'tool' => $this->name,
            'error' => $message,
            'error_code' => $code,
            'data' => $data,
            'timestamp' => date('c'),
        ];
    }

    /**
     * Validate that required arguments are present.
     */
    protected function requireArgs(array $args, array $required): ?array
    {
        foreach ($required as $key) {
            if (!isset($args[$key]) || $args[$key] === '') {
                return $this->error("Missing required argument: {$key}");
            }
        }
        return null;
    }

    /**
     * Hook called before execution. Override for pre-processing.
     */
    protected function beforeExecute(array $args): void {}

    /**
     * Hook called after execution. Override for post-processing.
     */
    protected function afterExecute(array $args, array $result): void {}
}
