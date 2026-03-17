# Routing

## Overview

PHPClaw uses role-based routing to assign AI requests to the appropriate provider and model. Roles are an abstraction layer between modules (which define what the agent does) and providers (which define where the request goes).

## Role-Based Model Assignment

Each role maps to a specific provider and model combination. When a module sends a request, it specifies its role. The ModelRouter resolves the role to a concrete provider and model.

```
Module (coding) ──► Role (coding) ──► Provider (ollama) + Model (codellama)
Module (reasoning) ──► Role (reasoning) ──► Provider (chatgpt) + Model (gpt-4o)
```

This indirection allows changing which model handles a given type of work without modifying any module code.

## Role Definitions

Roles are defined in `writable/agent/config/roles.json`:

```json
{
  "default": {
    "provider": "ollama",
    "model": "llama3",
    "description": "Default role for general conversation"
  },
  "coding": {
    "provider": "ollama",
    "model": "codellama",
    "description": "Code generation and engineering tasks",
    "fallback": ["default"]
  },
  "reasoning": {
    "provider": "chatgpt",
    "model": "gpt-4o",
    "description": "Complex reasoning and analysis",
    "fallback": ["default"]
  },
  "summarizer": {
    "provider": "ollama",
    "model": "llama3",
    "description": "Content summarization",
    "fallback": ["default"]
  },
  "system": {
    "provider": "ollama",
    "model": "llama3",
    "description": "System operations (heartbeat, health)",
    "fallback": []
  },
  "memory": {
    "provider": "ollama",
    "model": "llama3",
    "description": "Memory extraction and compaction",
    "fallback": ["default"]
  },
  "planner": {
    "provider": "chatgpt",
    "model": "gpt-4o",
    "description": "Task planning and decomposition",
    "fallback": ["reasoning", "default"]
  },
  "browser": {
    "provider": "ollama",
    "model": "llama3",
    "description": "Web content processing",
    "fallback": ["default"]
  },
  "tool_router": {
    "provider": "chatgpt",
    "model": "gpt-4o",
    "description": "Multi-tool coordination",
    "fallback": ["reasoning", "default"]
  }
}
```

## Module Role Assignment and Overrides

Modules specify their role in `modules.json`. The role determines the default routing path. Modules can also specify `provider_override` and `model_override` to bypass role-based routing entirely.

Resolution order:

1. Module `provider_override` / `model_override` (if set, used directly)
2. Module `role` resolved through `roles.json`
3. Fallback chain (if the primary role's provider is unavailable)
4. `default` role (ultimate fallback)

## Fallback Chains

Each role can define a `fallback` array listing other roles to try if the primary provider/model is unavailable (health check fails, provider disabled, model not found).

Fallback resolution:

```
Role: planner
  Primary: chatgpt / gpt-4o
  │
  ├── (unavailable) ──► Fallback 1: reasoning
  │                        chatgpt / gpt-4o
  │                        │
  │                        └── (unavailable) ──► Fallback 2: default
  │                                                ollama / llama3
  │
  └── (available) ──► Use chatgpt / gpt-4o
```

An empty fallback array (`"fallback": []`) means no fallback -- if the primary is unavailable, the request fails.

## Timeout and Retry Configuration

Timeout and retry settings are defined per-provider in `providers.json` and can be overridden per-role in `roles.json`:

```json
{
  "coding": {
    "provider": "ollama",
    "model": "codellama",
    "timeout": 180,
    "retries": 3,
    "retry_delay": 2,
    "fallback": ["default"]
  }
}
```

| Setting | Default | Description |
|---|---|---|
| `timeout` | 120 | Request timeout in seconds |
| `retries` | 2 | Number of retry attempts on failure |
| `retry_delay` | 1 | Delay between retries in seconds |

Retries are attempted on transient errors (timeouts, connection failures, 5xx responses). Non-transient errors (4xx, invalid response) are not retried.

## ModelRouter Class

The `ModelRouter` class implements the routing logic:

```php
class ModelRouter
{
    public function resolve(string $role, ?string $providerOverride = null, ?string $modelOverride = null): array;
    public function getProviderForRole(string $role): ProviderInterface;
    public function getModelForRole(string $role): string;
    public function resolveWithFallback(string $role): array;
}
```

The `resolve()` method returns an array with `provider` and `model` keys:

```php
$route = $router->resolve('coding');
// ['provider' => 'ollama', 'model' => 'codellama']

$route = $router->resolve('coding', 'chatgpt', 'gpt-4o');
// ['provider' => 'chatgpt', 'model' => 'gpt-4o']  (override)
```

## Examples

### Default Chat Routing

User starts a chat without specifying a module. The `default` role is used:

```
User message ──► default role ──► ollama / llama3
```

### Module-Specific Routing

User switches to the coding module with `/module coding`:

```
User message ──► coding module ──► coding role ──► ollama / codellama
```

### Fallback in Action

The planner module tries to use ChatGPT, but the API is down:

```
User message ──► planner module ──► planner role ──► chatgpt / gpt-4o (FAIL)
                                                 ──► reasoning role ──► chatgpt / gpt-4o (FAIL)
                                                 ──► default role ──► ollama / llama3 (OK)
```

### Override in Action

A module is configured with a provider override:

```json
{
  "coding": {
    "role": "coding",
    "provider_override": "chatgpt",
    "model_override": "gpt-4o"
  }
}
```

```
User message ──► coding module ──► chatgpt / gpt-4o (override, skip role resolution)
```
