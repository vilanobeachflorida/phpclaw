<?php

namespace App\Libraries\Providers;

/**
 * Contract for all LLM provider adapters.
 * Every provider (Ollama, ChatGPT, Claude Code, OpenLLM) implements this interface.
 */
interface ProviderInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function configure(array $config): void;
    public function healthCheck(): array;
    public function listModels(): array;
    public function chat(array $messages, array $options = []): array;
    public function send(string $prompt, array $options = []): array;
    public function isAvailable(): bool;
    public function getCapabilities(): array;
}
