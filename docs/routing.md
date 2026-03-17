# Model Routing

PHPClaw uses role-based routing to assign models to different types of work. This allows flexible model allocation without hardcoding provider and model names throughout the codebase.

## Role-Based Model Assignment

Every request to a model is associated with a role. The role determines which provider and model handle the request. This indirection means you can:

- Use a powerful model for coding tasks and a lightweight model for heartbeats
- Switch providers for a role without changing any module configuration
- Define fallback chains for reliability

## Role Definitions

Roles are defined in `writable/agent/config/roles.json`:

```json
{
  "default": {
    "provider": "ollama",
    "model": "llama3",
    "fallback": null
  },
  "coding": {
    "provider": "chatgpt",
    "model": "gpt-4o",
    "fallback": "default",
    "timeout": 120,
    "max_retries": 3
  },
  "reasoning": {
    "provider": "chatgpt",
    "model": "gpt-4o",
    "fallback": "default"
  },
  "summarizer": {
    "provider": "ollama",
    "model": "llama3",
    "fallback": null
  },
  "system": {
    "provider": "ollama",
    "model": "llama3",
    "fallback": null,
    "timeout": 10
  },
  "planning": {
    "provider": "chatgpt",
    "model": "gpt-4o",
    "fallback": "default"
  },
  "browsing": {
    "provider": "ollama",
    "model": "llama3",
    "fallback": "default"
  },
  "routing": {
    "provider": "ollama",
    "model": "llama3",
    "fallback": "default"
  }
}
```

### Role Fields

- **provider** -- the primary provider for this role.
- **model** -- the primary model for this role.
- **fallback** -- name of another role to try if the primary fails (null means no fallback).
- **timeout** -- request timeout in seconds (overrides provider default).
- **max_retries** -- maximum retry attempts before falling back.

## Module Role Assignment and Overrides

Modules declare their role in `modules.json`. The routing resolution follows this priority:

1. **Module provider/model override** -- if the module specifies `provider` and/or `model` directly, those are used.
2. **Role assignment** -- the module's `role` is looked up in `roles.json` to find the provider and model.
3. **Default role** -- if the module's role is not found in `roles.json`, the `default` role is used.

## Fallback Chains

When a request fails (timeout, API error, rate limit), the router follows the fallback chain:

```
coding role (chatgpt/gpt-4o)
    │ fails
    ▼
default role (ollama/llama3)
    │ fails
    ▼
Error returned to caller
```

Fallback chains are followed automatically. Each fallback attempt uses the timeout and retry settings of the target role. Circular fallback references are detected and prevented.

## Timeout and Retry Configuration

Timeouts and retries can be configured at multiple levels:

| Level | Setting | Description |
|---|---|---|
| Provider | `timeout` in `providers.json` | Default timeout for all requests to this provider |
| Role | `timeout` in `roles.json` | Override timeout for requests using this role |
| Role | `max_retries` in `roles.json` | Retry count before falling back |
| Provider | `retry.max_attempts` in `providers.json` | Provider-level retry count |
| Provider | `retry.delay_ms` in `providers.json` | Delay between retries in milliseconds |

Role-level settings take precedence over provider-level settings.

## ModelRouter Class

The `ModelRouter` class handles all routing logic:

```php
class ModelRouter
{
    public function route(string $role, array $messages, array $options = []): array;
    public function resolveProvider(string $role): ProviderInterface;
    public function resolveModel(string $role): string;
    public function getEffectiveConfig(string $role): array;
}
```

The `route()` method is the primary entry point. It resolves the provider and model, sends the request, handles retries, and follows fallback chains if needed.

## Routing Examples

### Basic Role Routing

A coding module sends a request:

```
Module: coding (role: coding)
    → roles.json lookup: coding → chatgpt / gpt-4o
    → Request sent to ChatGPT with gpt-4o
    → Response returned
```

### Fallback on Failure

ChatGPT is unreachable:

```
Module: coding (role: coding)
    → roles.json lookup: coding → chatgpt / gpt-4o
    → Request to ChatGPT fails (timeout)
    → Retry 1 fails
    → Retry 2 fails
    → Fallback: coding.fallback = "default"
    → roles.json lookup: default → ollama / llama3
    → Request sent to Ollama with llama3
    → Response returned
```

### Module Override

Heartbeat module with explicit provider:

```
Module: heartbeat (role: system, provider: ollama, model: llama3)
    → Module override detected
    → Skip roles.json lookup
    → Request sent directly to Ollama with llama3
    → Response returned
```
