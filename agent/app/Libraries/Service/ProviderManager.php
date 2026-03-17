<?php

namespace App\Libraries\Service;

use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Providers\ProviderInterface;
use App\Libraries\Providers\OllamaProvider;
use App\Libraries\Providers\OpenLLMProvider;
use App\Libraries\Providers\ClaudeCodeProvider;
use App\Libraries\Providers\ClaudeAPIProvider;
use App\Libraries\Providers\ChatGPTProvider;

/**
 * Manages provider lifecycle: loading, configuring, health checking.
 */
class ProviderManager
{
    private ConfigLoader $config;
    private array $providers = [];

    private static array $typeMap = [
        'ollama' => OllamaProvider::class,
        'openllm' => OpenLLMProvider::class,
        'claude_code' => ClaudeCodeProvider::class,
        'claude_api' => ClaudeAPIProvider::class,
        'chatgpt' => ChatGPTProvider::class,
    ];

    public function __construct(?ConfigLoader $config = null)
    {
        $this->config = $config ?? new ConfigLoader();
    }

    /**
     * Load and configure all enabled providers.
     */
    public function loadAll(): void
    {
        $providersConfig = $this->config->get('providers', 'providers', []);

        foreach ($providersConfig as $name => $config) {
            if (!($config['enabled'] ?? false)) continue;

            $type = $config['type'] ?? $name;
            $class = self::$typeMap[$type] ?? null;

            if (!$class || !class_exists($class)) continue;

            $provider = new $class();
            $provider->configure($config);
            $this->providers[$name] = $provider;
        }
    }

    public function get(string $name): ?ProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    public function all(): array
    {
        return $this->providers;
    }

    public function listEnabled(): array
    {
        $result = [];
        foreach ($this->providers as $name => $provider) {
            $result[] = [
                'name' => $name,
                'description' => $provider->getDescription(),
                'capabilities' => $provider->getCapabilities(),
            ];
        }
        return $result;
    }

    public function healthCheckAll(): array
    {
        $results = [];
        foreach ($this->providers as $name => $provider) {
            try {
                $results[$name] = $provider->healthCheck();
            } catch (\Throwable $e) {
                $results[$name] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }
        return $results;
    }
}
