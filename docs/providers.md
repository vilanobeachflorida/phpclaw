# Providers

Providers are adapters that connect PHPClaw to model APIs. Each provider handles authentication, request formatting, response parsing, health checks, and model discovery for a specific backend.

## ProviderInterface

All providers implement `ProviderInterface`:

```php
interface ProviderInterface
{
    public function getName(): string;
    public function chat(array $messages, array $options = []): array;
    public function listModels(): array;
    public function healthCheck(): bool;
    public function isEnabled(): bool;
}
```

## BaseProvider

`BaseProvider` is an abstract class providing common functionality:

- Configuration loading from `providers.json`
- HTTP client setup with timeout and retry handling
- Response normalization
- Error handling and logging

Custom providers should extend `BaseProvider`.

## Built-in Providers

### Ollama

Connects to a local Ollama instance. Supports all Ollama-compatible models.

- **Endpoint**: `http://localhost:11434` (default)
- **Authentication**: None required
- **Model discovery**: Automatic via Ollama API
- **Use case**: Local inference with open models

### OpenLLM

Connects to OpenAI-compatible API endpoints, including self-hosted OpenLLM servers, vLLM, and other compatible backends.

- **Endpoint**: Configurable
- **Authentication**: API key (optional, depending on server)
- **Model discovery**: Via API endpoint
- **Use case**: Self-hosted model servers with OpenAI-compatible APIs

### ChatGPT

Connects to the OpenAI API for GPT models.

- **Endpoint**: `https://api.openai.com/v1` (default)
- **Authentication**: API key required
- **Model discovery**: Via OpenAI models endpoint
- **Use case**: Cloud-hosted OpenAI models (GPT-4o, GPT-4o-mini, etc.)

### Claude Code

Integrates with the Claude Code CLI tool for Anthropic model access.

- **Authentication**: Managed by the Claude Code CLI
- **Model discovery**: Via CLI capabilities
- **Use case**: Anthropic models through the Claude Code interface

## Provider Configuration

Providers are configured in `writable/agent/config/providers.json`:

```json
{
  "ollama": {
    "type": "ollama",
    "endpoint": "http://localhost:11434",
    "models": ["llama3", "codellama"],
    "default_model": "llama3",
    "enabled": true,
    "timeout": 120,
    "retry": {
      "max_attempts": 3,
      "delay_ms": 1000
    }
  },
  "chatgpt": {
    "type": "chatgpt",
    "endpoint": "https://api.openai.com/v1",
    "api_key": "sk-...",
    "models": ["gpt-4o", "gpt-4o-mini"],
    "default_model": "gpt-4o",
    "enabled": true,
    "timeout": 60
  }
}
```

Each provider entry includes:
- **type** -- the provider adapter to use
- **endpoint** -- the API base URL
- **api_key** -- authentication credential (if required)
- **models** -- list of available models (or discovered automatically)
- **default_model** -- the model to use when none is specified
- **enabled** -- whether this provider is active
- **timeout** -- request timeout in seconds
- **retry** -- retry configuration for failed requests

## Health Checks

Each provider implements a `healthCheck()` method that verifies connectivity and readiness. Health checks are run:

- On agent startup
- Periodically by the service loop
- On demand via `agent:providers`

A health check typically sends a lightweight request (e.g., list models) to verify the backend is reachable and responding.

## Model Listing

The `listModels()` method returns all models available from a provider. This is used by:

- `agent:models` command to display available models
- The `/model` slash command in chat
- The ModelRouter for validation

## Adding Custom Providers

### Using the Scaffold Command

```bash
php spark agent:provider:scaffold MyProvider
```

This generates a new provider file with the required structure.

### Implementation

```php
<?php

namespace App\Libraries\Agent\Providers;

class MyProvider extends BaseProvider
{
    protected string $name = 'myprovider';

    public function chat(array $messages, array $options = []): array
    {
        // Send messages to your model API
        // Return normalized response
    }

    public function listModels(): array
    {
        // Return array of available model names
    }

    public function healthCheck(): bool
    {
        // Return true if the backend is reachable
    }
}
```

After creating the provider class, add its configuration to `providers.json` and it will be available for use.

## ChatGPT OAuth Notes

PHPClaw does not manage OpenAI authentication or OAuth flows. The API key must be obtained externally from the OpenAI platform and supplied in `providers.json`. PHPClaw never stores credentials beyond the configuration file. Protect your `providers.json` file with appropriate filesystem permissions.

## Claude Code CLI Integration

The Claude Code provider works differently from API-based providers. Instead of making HTTP requests, it invokes the `claude` CLI tool as a subprocess. This means:

- The Claude Code CLI must be installed and authenticated separately
- Authentication is managed by the CLI tool, not by PHPClaw
- Model selection is handled through CLI flags
- The provider translates between PHPClaw's message format and the CLI's input/output format
