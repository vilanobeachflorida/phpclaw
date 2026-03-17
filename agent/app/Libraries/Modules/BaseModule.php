<?php

namespace App\Libraries\Modules;

use App\Libraries\Router\ModelRouter;
use App\Libraries\Storage\ConfigLoader;

/**
 * Base class for all agent modules.
 * A module is a model-aware component with a specific purpose (reasoning, coding, etc.).
 */
abstract class BaseModule
{
    protected string $name = '';
    protected string $description = '';
    protected string $role = 'reasoning';
    protected array $config = [];
    protected ?ModelRouter $router = null;

    public function __construct(array $config = [], ?ModelRouter $router = null)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->router = $router;
    }

    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getRole(): string { return $this->config['role'] ?? $this->role; }
    public function isEnabled(): bool { return $this->config['enabled'] ?? true; }
    public function getTools(): array { return $this->config['tools'] ?? []; }
    public function getConfig(): array { return $this->config; }

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'role' => $this->role,
            'tools' => [],
            'cache_policy' => 'none',
            'memory_policy' => 'full',
            'timeout' => 120,
            'retry' => 2,
        ];
    }

    /**
     * Get the system prompt for this module.
     */
    public function getSystemPrompt(): string
    {
        $promptFile = $this->config['prompt_file'] ?? null;
        if ($promptFile) {
            $path = WRITEPATH . 'agent/prompts/' . $promptFile;
            if (file_exists($path)) {
                return file_get_contents($path);
            }
        }
        return $this->getDefaultPrompt();
    }

    abstract protected function getDefaultPrompt(): string;

    /**
     * Execute the module's primary function.
     */
    public function execute(string $input, array $context = []): array
    {
        $messages = [];

        $systemPrompt = $this->getSystemPrompt();
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Include conversation context if provided
        if (!empty($context['history'])) {
            foreach ($context['history'] as $msg) {
                $messages[] = $msg;
            }
        }

        $messages[] = ['role' => 'user', 'content' => $input];

        if ($this->router) {
            return $this->router->chat($this->getRole(), $messages);
        }

        return ['success' => false, 'error' => 'No router configured for module: ' . $this->name];
    }
}
