# Modules

## Overview

Modules are role-based configurations that combine a system prompt, a set of tools, caching and memory policies, and optional provider/model overrides. They define how the agent behaves for different types of tasks.

## BaseModule Class

```php
abstract class BaseModule
{
    protected string $name;
    protected string $role;
    protected array $tools = [];
    protected array $config = [];

    public function getName(): string;
    public function getRole(): string;
    public function getTools(): array;
    public function getConfig(): array;
    public function getPrompt(): string;
    public function getCachePolicy(): array;
    public function getMemoryPolicy(): array;
}
```

## Built-in Modules

### heartbeat

System health monitoring module. Used by the service loop to verify provider connectivity and basic responsiveness.

- **Role**: `system`
- **Tools**: None
- **Cache Policy**: No caching
- **Memory Policy**: No memory
- **Prompt**: `prompts/modules/heartbeat.md`

### reasoning

General-purpose reasoning and analysis. Used for complex problem-solving that requires step-by-step thinking.

- **Role**: `reasoning`
- **Tools**: `memory_query`, `calculator`
- **Cache Policy**: Cache responses for identical prompts (TTL: 1 hour)
- **Memory Policy**: Extract notes, session scope
- **Prompt**: `prompts/modules/reasoning.md`

### coding

Software engineering tasks. Code generation, refactoring, debugging, and review.

- **Role**: `coding`
- **Tools**: `file_read`, `file_write`, `file_list`, `file_search`, `shell_exec`, `grep`
- **Cache Policy**: No caching
- **Memory Policy**: Extract notes, session and global scope
- **Prompt**: `prompts/modules/coding.md`

### summarizer

Content summarization. Produces concise summaries of documents, conversations, and data.

- **Role**: `summarizer`
- **Tools**: `file_read`, `web_fetch`
- **Cache Policy**: Cache summaries (TTL: 24 hours)
- **Memory Policy**: No memory
- **Prompt**: `prompts/modules/summarizer.md`

### memory

Memory management. Analyzes conversation logs, extracts notes, and produces compacted summaries.

- **Role**: `memory`
- **Tools**: `memory_query`
- **Cache Policy**: No caching
- **Memory Policy**: Read-only (does not write to its own memory)
- **Prompt**: `prompts/modules/memory.md`

### planner

Task planning and decomposition. Breaks complex tasks into structured, actionable steps.

- **Role**: `planner`
- **Tools**: `memory_query`, `task_create`
- **Cache Policy**: No caching
- **Memory Policy**: Extract notes, session scope
- **Prompt**: `prompts/modules/planner.md`

### browser

Web content processing. Fetches and analyzes web pages, extracts relevant information.

- **Role**: `browser`
- **Tools**: `web_fetch`, `grep`
- **Cache Policy**: Cache fetched content (TTL: 1 hour)
- **Memory Policy**: No memory
- **Prompt**: `prompts/modules/browser.md`

### tool_router

Multi-tool coordination. Determines which tools to use and orchestrates multi-step tool operations.

- **Role**: `tool_router`
- **Tools**: All available tools
- **Cache Policy**: No caching
- **Memory Policy**: Extract notes, session scope
- **Prompt**: `prompts/modules/tool_router.md`

## Module Configuration

Modules are configured in `writable/agent/config/modules.json`:

```json
{
  "coding": {
    "role": "coding",
    "tools": ["file_read", "file_write", "file_list", "file_search", "shell_exec", "grep"],
    "cache_policy": {
      "enabled": false
    },
    "memory_policy": {
      "enabled": true,
      "extract_notes": true,
      "scopes": ["session", "global"]
    },
    "prompt": "prompts/modules/coding.md"
  }
}
```

### Configuration Fields

| Field | Type | Description |
|---|---|---|
| `role` | string | The role this module uses for model routing |
| `tools` | array | List of tool names available to this module |
| `cache_policy` | object | Caching configuration |
| `cache_policy.enabled` | bool | Whether response caching is active |
| `cache_policy.ttl` | int | Cache TTL in seconds |
| `memory_policy` | object | Memory configuration |
| `memory_policy.enabled` | bool | Whether memory is active |
| `memory_policy.extract_notes` | bool | Whether to extract notes from responses |
| `memory_policy.scopes` | array | Memory scopes to write to |
| `prompt` | string | Path to the module prompt file (relative to `writable/agent/`) |

## Module-to-Role Mapping

Each module specifies a `role` that determines which provider and model handle its requests. The role is resolved by the ModelRouter using the configuration in `roles.json`. This allows the same module to use different models by changing the role mapping without modifying the module itself.

## Provider and Model Overrides

Modules can optionally specify provider and model overrides that bypass role-based routing:

```json
{
  "coding": {
    "role": "coding",
    "provider_override": "chatgpt",
    "model_override": "gpt-4o",
    "tools": ["file_read", "file_write"]
  }
}
```

When overrides are present, the ModelRouter uses them directly instead of consulting the role configuration. This is useful for pinning a specific module to a particular model.
