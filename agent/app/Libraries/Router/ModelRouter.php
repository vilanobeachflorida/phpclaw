<?php

namespace App\Libraries\Router;

use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Providers\ProviderInterface;

/**
 * Routes requests to the appropriate provider+model based on role or module config.
 * Supports fallback chains, timeouts, and retry policies.
 */
class ModelRouter
{
    private ConfigLoader $config;
    private array $providers = [];

    public function __construct(?ConfigLoader $config = null)
    {
        $this->config = $config ?? new ConfigLoader();
    }

    /**
     * Register a provider instance.
     */
    public function registerProvider(string $name, ProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    /**
     * Resolve provider+model for a given role.
     */
    public function resolveRole(string $role): array
    {
        $roles = $this->config->get('roles', 'roles', []);
        $roleConfig = $roles[$role] ?? null;

        if (!$roleConfig) {
            // Fall back to app defaults
            return [
                'provider' => $this->config->get('app', 'default_provider', 'ollama'),
                'model' => $this->config->get('app', 'default_model', 'llama3'),
                'timeout' => 120,
                'retry' => 2,
                'fallback' => [],
            ];
        }

        return [
            'provider' => $roleConfig['provider'],
            'model' => $roleConfig['model'],
            'timeout' => $roleConfig['timeout'] ?? 120,
            'retry' => $roleConfig['retry'] ?? 2,
            'fallback' => $roleConfig['fallback'] ?? [],
        ];
    }

    /**
     * Resolve provider+model for a module (may override role).
     */
    public function resolveModule(string $moduleName): array
    {
        $modules = $this->config->get('modules', 'modules', []);
        $moduleConfig = $modules[$moduleName] ?? null;

        if (!$moduleConfig) {
            return $this->resolveRole('reasoning');
        }

        // Direct provider/model override takes precedence
        if (!empty($moduleConfig['provider_override'])) {
            return [
                'provider' => $moduleConfig['provider_override'],
                'model' => $moduleConfig['model_override'] ?? 'default',
                'timeout' => $moduleConfig['timeout'] ?? 120,
                'retry' => $moduleConfig['retry'] ?? 2,
                'fallback' => [],
            ];
        }

        // Otherwise use the assigned role
        $role = $moduleConfig['role'] ?? 'reasoning';
        return $this->resolveRole($role);
    }

    /**
     * Get a provider instance by name.
     */
    public function getProvider(string $name): ?ProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Execute a chat request with routing, fallback, and retry logic.
     */
    public function chat(string $role, array $messages, array $options = []): array
    {
        $route = $this->resolveRole($role);
        $providerNames = array_merge([$route['provider']], $route['fallback']);

        foreach ($providerNames as $providerName) {
            $provider = $this->getProvider($providerName);
            if (!$provider) continue;

            // Set up streaming: pass progress callback and enable streaming
            // if the provider supports it and a callback is provided
            $capabilities = $provider->getCapabilities();
            if (isset($options['progress_callback']) && method_exists($provider, 'setProgressCallback')) {
                if ($capabilities['streaming'] ?? false) {
                    $provider->setProgressCallback($options['progress_callback']);
                    $options['stream'] = true;
                } else {
                    // Provider doesn't support streaming — no progress updates
                    $provider->setProgressCallback(null);
                    $options['stream'] = false;
                }
            }

            $maxRetries = $route['retry'];
            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                try {
                    $result = $provider->chat($messages, array_merge($options, [
                        'model' => $route['model'],
                        'timeout' => $route['timeout'] ?? 120,
                    ]));

                    if ($result['success'] ?? false) {
                        return $result;
                    }
                } catch (\Throwable $e) {
                    // Log and continue to next retry/fallback
                }

                if ($attempt < $maxRetries) {
                    usleep(500000 * ($attempt + 1)); // Backoff
                }
            }
        }

        return [
            'success' => false,
            'error' => 'All providers failed for role: ' . $role,
            'providers_tried' => $providerNames,
        ];
    }
}
