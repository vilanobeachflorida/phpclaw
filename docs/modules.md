# Modules

## Overview

Modules are role-based configurations that combine a system prompt, a set of tools, caching and memory policies, and optional provider/model overrides. They define how the agent behaves for different types of tasks.

PHPClaw includes **LLM-powered module routing** — when on the default `reasoning` module, the agent uses a fast classification call to the LLM to determine which module best fits your request. For example, "create a website" routes to `coding`, while "fetch this URL" routes to `browser`, and "how does TCP work?" stays on `reasoning`. The LLM understands intent semantically, not just keywords. A regex fallback handles routing if the LLM call fails. You can also manually set a module with `/module <name>`.

## Built-in Modules

### reasoning (default)

General-purpose reasoning and analysis. The default module for questions, explanations, and problem-solving.

- **Role**: `reasoning`
- **Tools**: `file_read`, `grep_search`, `dir_list`
- **Cache Policy**: Standard (1 hour TTL)
- **Memory Policy**: Full (extracts notes, session + global scope)
- **Prompt**: Deep reasoning with step-by-step analysis

### coding

Software engineering — code generation, refactoring, debugging, and full project creation. This is the powerhouse module with access to all development tools.

- **Role**: `coding`
- **Tools**: `file_read`, `file_write`, `file_append`, `dir_list`, `mkdir`, `grep_search`, `shell_exec`, `git_ops`, `code_patch`, `diff_review`, `exec_target`, `project_detect`, `test_runner`, `lint_check`, `code_symbols`, `task_planner`, `build_runner`, `error_parser`, `context_manager` (19 tools)
- **Cache Policy**: No caching
- **Memory Policy**: Full
- **Prompt**: Action-oriented coding agent that executes tasks directly, never narrates. Includes guidance for multi-file projects, verification, testing, and code quality.
- **Auto-detected when**: Request mentions creating/building/writing code, files, websites, apps, scripts; mentions programming languages; asks to refactor, debug, or fix code; mentions git operations, dependencies, or deployment.

### browser

Web content fetching and processing. For scraping, reading, and analyzing web pages.

- **Role**: `browser`
- **Tools**: `browser_fetch`, `browser_text`, `http_get`, `http_request`
- **Cache Policy**: Standard (1 hour TTL)
- **Memory Policy**: Summary only
- **Prompt**: Web content processing and extraction
- **Auto-detected when**: Request mentions fetching, scraping, or visiting URLs; contains a URL; asks to get content from a web page.

### planner

Task planning and decomposition. Breaks complex tasks into structured, actionable steps.

- **Role**: `planning`
- **Tools**: `file_read`, `dir_list`, `grep_search`
- **Cache Policy**: Standard
- **Memory Policy**: Full
- **Prompt**: Structured planning with dependencies, risks, and execution order
- **Auto-detected when**: Request asks to plan, outline, break down, or decompose a task; asks "how should I approach..."; asks for step-by-step guidance.

### summarizer

Content summarization. Produces concise summaries of documents, conversations, and data.

- **Role**: `summarization`
- **Tools**: `file_read`
- **Cache Policy**: Aggressive (long TTL)
- **Memory Policy**: Summary only
- **Prompt**: Concise, accurate summaries preserving key information
- **Auto-detected when**: Request asks to summarize, condense, or give a TLDR; asks for key points or takeaways.

### tool_router

Catch-all module with access to every tool. Used for multi-tool coordination and tasks that don't fit neatly into other modules.

- **Role**: `fast_response`
- **Tools**: `*` (all 34 tools)
- **Cache Policy**: No caching
- **Memory Policy**: Full
- **Prompt**: Tool routing and multi-step coordination

### memory

Internal module for memory management and compaction. Not typically used directly.

- **Role**: `memory_compaction`
- **Tools**: `file_read`, `file_write`, `dir_list`
- **Cache Policy**: No caching
- **Memory Policy**: None (doesn't write to its own memory)

### heartbeat

Internal module for system health monitoring. Used by the background service.

- **Role**: `heartbeat`
- **Tools**: None
- **Cache Policy**: No caching
- **Memory Policy**: None

## Auto-Detection

When the user is on the default `reasoning` module, PHPClaw automatically analyzes each message and routes it to the best module. This happens transparently — the user doesn't need to manually switch modules for common tasks.

### How It Works

1. **LLM classification (primary)** — A lightweight call using the `fast_response` role sends your message with a classification prompt. The model returns a single word: `reasoning`, `coding`, `browser`, `planner`, or `summarizer`. This understands nuance — "how do I make a bash script?" stays on reasoning, while "make me a bash script" routes to coding.

2. **Regex fallback** — If the LLM call fails (provider down, timeout), a simple regex-based classifier handles common patterns like URLs → browser, "create a website" → coding, etc.

3. **No routing** — If both methods return `reasoning`, nothing changes — the request stays on the default module.

The classification call uses minimal tokens (~200 in, ~5 out) and adds negligible latency. The cost is tracked in the session usage.

If the user manually sets a module with `/module coding`, auto-routing is disabled and all requests go to that module until changed.

### Examples

| User Input | Auto-Detected Module |
|-----------|---------------------|
| "create a PHP website for my business" | coding |
| "fetch https://example.com and summarize it" | browser |
| "plan out the database migration" | planner |
| "summarize the README" | summarizer |
| "what is the difference between TCP and UDP?" | reasoning (question) |
| "how do I make a bash script?" | reasoning (question) |
| "build a REST API with Express" | coding |
| "break down the deployment process into steps" | planner |
| "what's on https://news.ycombinator.com?" | browser |
| "refactor the auth middleware" | coding |
| "give me a TLDR of this log file" | summarizer |

## Module Configuration

Modules are configured in `writable/agent/config/modules.json`:

```json
{
    "modules": {
        "coding": {
            "enabled": true,
            "description": "Code generation and modification",
            "role": "coding",
            "provider_override": null,
            "model_override": null,
            "tools": ["file_read", "file_write", "file_append", "dir_list", "mkdir", "grep_search", "shell_exec", "git_ops", "code_patch", "diff_review", "exec_target", "project_detect", "test_runner", "lint_check", "code_symbols", "task_planner", "build_runner", "error_parser", "context_manager"],
            "cache_policy": "none",
            "memory_policy": "full",
            "timeout": 180,
            "retry": 2,
            "prompt_file": "modules/coding.md"
        }
    }
}
```

### Configuration Fields

| Field | Type | Description |
|---|---|---|
| `enabled` | bool | Whether the module is available |
| `description` | string | Human-readable description |
| `role` | string | The role this module uses for model routing |
| `provider_override` | string/null | Override the provider (bypasses role routing) |
| `model_override` | string/null | Override the model |
| `tools` | array | List of tool names available to this module (`*` for all) |
| `cache_policy` | string | Caching strategy: `none`, `standard`, `aggressive` |
| `memory_policy` | string | Memory strategy: `none`, `summary_only`, `full` |
| `timeout` | int | Maximum seconds per LLM call |
| `retry` | int | Number of retries on failure |
| `prompt_file` | string | Path to the module prompt file (relative to `writable/agent/prompts/`) |

## Module-to-Role Mapping

Each module specifies a `role` that determines which provider and model handle its requests. The role is resolved by the ModelRouter using the configuration in `roles.json`. This allows the same module to use different models by changing the role mapping without modifying the module itself.

## Provider and Model Overrides

Modules can optionally specify provider and model overrides that bypass role-based routing:

```json
{
    "coding": {
        "role": "coding",
        "provider_override": "claude_api",
        "model_override": "claude-sonnet-4-20250514"
    }
}
```

When overrides are present, the ModelRouter uses them directly instead of consulting the role configuration. This is useful for pinning a specific module to a particular model — for example, using a cloud model for coding while keeping local models for everything else.

## Switching Modules

### Automatic (recommended)

Just type your request. PHPClaw detects the task type and routes to the best module.

### Manual

```
/module coding      # Switch to coding module
/module reasoning   # Switch back to default
/module browser     # Switch to browser module
/module             # Show current module
```

Manual module selection overrides auto-detection until you switch back to `reasoning`.
