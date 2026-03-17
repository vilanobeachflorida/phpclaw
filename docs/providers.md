# Providers

## Overview

Providers are adapters that connect PHPClaw to AI model backends. Each provider handles the specifics of communicating with a particular API while exposing a uniform interface.

## ProviderInterface and BaseProvider

All providers implement `ProviderInterface`:

```php
interface ProviderInterface
{
    public function getName(): string;
    public function getType(): string;
    public function isEnabled(): bool;
    public function healthCheck(): bool;
    public function listModels(): array;
    public function chat(array $messages, array $options = []): array;
}
```

The `BaseProvider` abstract class provides common functionality:

```php
abstract class BaseProvider implements ProviderInterface
{
    protected string $name;
    protected string $type;
    protected array $config;
    protected bool $enabled;

    public function getName(): string;
    public function getType(): string;
    public function isEnabled(): bool;
    abstract public function healthCheck(): bool;
    abstract public function listModels(): array;
    abstract public function chat(array $messages, array $options = []): array;

    protected function httpRequest(string $method, string $url, array $data = []): array;
}
```

## Built-in Providers

### Ollama

Local model execution through the Ollama API.

- **Type**: `ollama`
- **Base URL**: `http://localhost:11434` (default)
- **Authentication**: None required
- **Models**: Any model pulled into Ollama (llama3, codellama, mistral, etc.)
- **Features**: Streaming support, tool calling (model-dependent)

### OpenLLM

Compatible with OpenAI-style API endpoints, including self-hosted and third-party services.

- **Type**: `openllm`
- **Base URL**: Configurable
- **Authentication**: API key (Bearer token)
- **Models**: Depends on the backend service
- **Features**: OpenAI-compatible chat completions endpoint

### ChatGPT

OpenAI's ChatGPT API.

- **Type**: `chatgpt`
- **Base URL**: `https://api.openai.com/v1` (default)
- **Authentication**: API key required
- **Models**: gpt-4o, gpt-4, gpt-3.5-turbo, etc.
- **Features**: Full tool calling support, streaming, JSON mode

### Claude Code

Integration with the Claude Code CLI.

- **Type**: `claude_code`
- **Base URL**: N/A (uses CLI)
- **Authentication**: Handled by Claude CLI authentication
- **Models**: Claude models available through the CLI
- **Features**: Code-focused interactions, file operations

## Provider Configuration

Providers are configured in `writable/agent/config/providers.json`:

```json
{
  "ollama": {
    "type": "ollama",
    "base_url": "http://localhost:11434",
    "enabled": true,
    "options": {
      "timeout": 120,
      "retries": 2
    }
  },
  "chatgpt": {
    "type": "chatgpt",
    "base_url": "https://api.openai.com/v1",
    "api_key": "sk-...",
    "enabled": true,
    "options": {
      "timeout": 60,
      "retries": 3
    }
  },
  "openllm": {
    "type": "openllm",
    "base_url": "http://localhost:3000",
    "api_key": "your-key",
    "enabled": false,
    "options": {}
  },
  "claude": {
    "type": "claude_code",
    "enabled": true,
    "options": {
      "cli_path": "/usr/local/bin/claude"
    }
  }
}
```

## Health Checks

Each provider implements a `healthCheck()` method that verifies connectivity:

- **Ollama**: Calls `GET /api/tags` to verify the server is running.
- **OpenLLM**: Calls `GET /v1/models` to verify the endpoint responds.
- **ChatGPT**: Calls `GET /v1/models` with authentication to verify the API key is valid.
- **Claude Code**: Checks that the `claude` CLI binary exists and is executable.

Health checks are run by `agent:providers` and periodically by the service loop.

## Model Listing

The `listModels()` method returns available models for each provider:

```bash
php spark agent:models
```

Output example:

```
Provider: ollama
  - llama3
  - codellama
  - mistral

Provider: chatgpt
  - gpt-4o
  - gpt-4
  - gpt-3.5-turbo
```

## Adding Custom Providers

### Scaffold a New Provider

```bash
php spark agent:provider:scaffold MyCustomProvider
```

This generates a provider class from the template at `writable/agent/templates/provider.php.tpl`.

### Implement the Provider

Fill in the abstract methods:

```php
<?php

namespace App\Libraries\Agent\Providers;

class MyCustomProvider extends BaseProvider
{
    protected string $type = 'my_custom';

    public function healthCheck(): bool
    {
        // Check connectivity to your backend
    }

    public function listModels(): array
    {
        // Return available model names
    }

    public function chat(array $messages, array $options = []): array
    {
        // Send messages to your backend and return the response
    }
}
```

### Register the Provider

Add the provider configuration to `providers.json`:

```json
{
  "my_custom": {
    "type": "my_custom",
    "base_url": "http://localhost:5000",
    "enabled": true
  }
}
```

## ChatGPT OAuth Notes

PHPClaw does not manage OpenAI account creation, billing, or OAuth flows. The API key must be obtained externally from the OpenAI platform and provided in the provider configuration. PHPClaw stores the key in `providers.json` and uses it for Bearer token authentication.

For security, consider setting the API key via environment variable rather than storing it directly in the config file:

```json
{
  "chatgpt": {
    "type": "chatgpt",
    "api_key_env": "OPENAI_API_KEY",
    "enabled": true
  }
}
```

## Claude Code CLI Integration

The Claude Code provider works differently from HTTP-based providers. Instead of making API calls, it invokes the `claude` CLI binary as a subprocess. This means:

- The `claude` CLI must be installed and on the system PATH (or configured with `cli_path`).
- Authentication is handled by the CLI's own auth mechanism.
- Model selection is handled by the CLI based on your Anthropic account.
- Communication happens through stdin/stdout of the subprocess.
