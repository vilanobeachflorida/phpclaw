# Modules

Modules are role-specific configurations that bundle a system prompt, tool access, cache policy, memory policy, and model routing into a named profile. They allow the agent to adopt different personas and capabilities depending on the task.

## BaseModule

All modules are defined as configuration entries (not PHP classes). The `BaseModule` class provides the runtime wrapper:

```php
class BaseModule
{
    public function getName(): string;
    public function getRole(): string;
    public function getTools(): array;
    public function getPrompt(): string;
    public function getCachePolicy(): array;
    public function getMemoryPolicy(): array;
    public function getProviderOverride(): ?string;
    public function getModelOverride(): ?string;
}
```

## Built-in Modules

### heartbeat

System health monitoring. Used by the service loop to verify model connectivity.

- **Role**: system
- **Tools**: none
- **Cache**: disabled
- **Memory**: disabled

### reasoning

General-purpose reasoning and analysis. Step-by-step problem solving.

- **Role**: reasoning
- **Tools**: memory_search
- **Cache**: enabled (short TTL)
- **Memory**: enabled

### coding

Software engineering tasks. Code generation, review, and analysis.

- **Role**: coding
- **Tools**: file_read, file_write, file_list, file_search, shell_exec, grep
- **Cache**: disabled
- **Memory**: enabled

### summarizer

Content summarization. Produces concise summaries of text, conversations, and documents.

- **Role**: summarizer
- **Tools**: file_read, memory_search
- **Cache**: enabled
- **Memory**: disabled

### memory

Memory management and compaction. Analyzes logs and extracts key information.

- **Role**: memory
- **Tools**: memory_search
- **Cache**: disabled
- **Memory**: disabled (operates on memory but does not write to its own scope)

### planner

Task planning and decomposition. Breaks complex tasks into actionable steps.

- **Role**: planning
- **Tools**: task_create, memory_search
- **Cache**: disabled
- **Memory**: enabled

### browser

Web content processing. Fetches and analyzes web pages.

- **Role**: browsing
- **Tools**: web_fetch
- **Cache**: enabled (long TTL)
- **Memory**: disabled

### tool_router

Tool coordination. Determines which tools to use and in what order for multi-step operations.

- **Role**: routing
- **Tools**: all tools available
- **Cache**: disabled
- **Memory**: enabled

## Module Configuration

Modules are defined in `writable/agent/config/modules.json`:

```json
{
  "coding": {
    "role": "coding",
    "tools": ["file_read", "file_write", "file_list", "file_search", "shell_exec", "grep"],
    "prompt": "prompts/modules/coding.md",
    "cache_policy": {
      "enabled": false
    },
    "memory_policy": {
      "enabled": true,
      "scope": "module",
      "auto_compact": true
    },
    "provider": null,
    "model": null
  }
}
```

### Configuration Fields

- **role** -- the role name used for model routing (maps to `roles.json`).
- **tools** -- array of tool names this module can access. The agent will only offer these tools when operating under this module.
- **prompt** -- path to the module's system prompt file, relative to `writable/agent/`.
- **cache_policy** -- controls response caching for this module.
  - `enabled` -- whether to cache responses.
  - `ttl` -- cache time-to-live in seconds (if enabled).
- **memory_policy** -- controls memory behavior for this module.
  - `enabled` -- whether to record and use memory.
  - `scope` -- memory scope (`global`, `session`, `module`, `task`).
  - `auto_compact` -- whether to trigger compaction automatically.
- **provider** -- override the default provider for this module (null uses role-based routing).
- **model** -- override the default model for this module (null uses role-based routing).

## Module-to-Role Mapping

Each module specifies a `role` that determines which model handles its requests. The role is resolved through `roles.json` to find the appropriate provider and model. This indirection allows changing model assignments without modifying module configurations.

For example:
- The `coding` module uses the `coding` role
- The `coding` role maps to `chatgpt` provider with `gpt-4o` model
- Changing the coding role's model in `roles.json` affects all modules using that role

## Provider and Model Overrides

A module can bypass role-based routing by specifying `provider` and/or `model` directly:

```json
{
  "heartbeat": {
    "role": "system",
    "provider": "ollama",
    "model": "llama3",
    "tools": [],
    "prompt": "prompts/modules/heartbeat.md"
  }
}
```

When overrides are set, the ModelRouter uses them directly instead of consulting `roles.json`. This is useful for modules that need a specific model regardless of role configuration.
