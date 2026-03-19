# Tools

## Overview

Tools are callable actions that the agent can invoke during chat and task execution. PHPClaw ships with 25 built-in tools and supports adding custom tools through scaffolding.

## ToolInterface and BaseTool

All tools implement `ToolInterface`:

```php
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getInputSchema(): array;
    public function execute(array $args): array;
    public function getConfig(): array;
    public function isEnabled(): bool;
}
```

The `BaseTool` abstract class provides common functionality:

```php
abstract class BaseTool implements ToolInterface
{
    protected string $name;
    protected string $description;
    protected array $config;

    public function getName(): string;
    public function getDescription(): string;
    public function getConfig(): array;
    public function isEnabled(): bool;
    public function getInputSchema(): array;

    protected function success(mixed $data, string $message = 'OK'): array;
    protected function error(string $message, int $code = 0, $data = null): array;
    protected function requireArgs(array $args, array $required): ?array;
    protected function getDefaultConfig(): array;
}
```

## Built-in Tools

### File Operations

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `file_read` | Read the contents of a file | `path`, `offset`, `limit` |
| `file_write` | Write content to a file | `path`, `content` |
| `file_append` | Append content to a file | `path`, `content` |
| `dir_list` | List files in a directory | `path` |
| `mkdir` | Create directories | `path` |
| `move_file` | Move or rename files | `source`, `destination` |
| `delete_file` | Delete files | `path` |
| `code_patch` | Surgical code editing via exact string replacement | `path`, `old_string`, `new_string`, `replace_all` |
| `archive_extract` | Create and extract archives (ZIP, tar.gz, tar.bz2) | `action`, `archive_path`, `destination`, `files`, `format` |

### Search & Analysis

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `grep_search` | Search file contents with regex patterns | `pattern`, `path`, `recursive`, `max_results` |
| `git_ops` | Structured git operations with parsed JSON output | `operation`, `path`, `ref`, `max_count` |
| `diff_review` | Analyze code diffs with structured per-hunk output | `mode`, `path_a`, `path_b`, `context_lines` |

### Network & Web

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `http_get` | Make HTTP GET requests | `url`, `headers`, `timeout` |
| `http_request` | Full HTTP client (all methods, headers, body, auth) | `url`, `method`, `headers`, `body`, `json`, `form` |
| `browser_fetch` | Fetch and parse web pages | `url`, `timeout` |
| `browser_text` | Extract text from web pages | `url`, `timeout` |

### System & Process

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `shell_exec` | Execute shell commands | `command`, `cwd`, `timeout` |
| `system_info` | Get system information | (none) |
| `process_manager` | Start, monitor, and stop background processes | `action`, `command`, `name`, `pid`, `tail_lines` |

### Data & Integration

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `db_query` | Execute SQL queries (MySQL, PostgreSQL, SQLite) | `query`, `params`, `connection` |
| `image_generate` | Generate images from text prompts | `prompt`, `provider`, `size`, `filename` |
| `cron_schedule` | Create and manage scheduled recurring tasks | `action`, `interval`, `command`, `type` |
| `notification_send` | Send notifications via multiple channels | `channel`, `message`, `title`, `to` |

### Memory

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `memory_write` | Save to long-term memory | `content`, `type`, `tags` |
| `memory_read` | Query persistent memory | `query` |

## Tool Details

### git_ops

Runs structured git operations without exposing raw shell access. Returns parsed JSON output instead of raw text.

**Supported operations:** `status`, `diff`, `log`, `blame`, `branch`, `show`, `stash_list`, `tag`

```
# Get current status
{"operation": "status"}

# View recent commits
{"operation": "log", "max_count": 10}

# Diff against a ref
{"operation": "diff", "ref": "HEAD~3"}
```

### code_patch

Safer alternative to `file_write` for modifications. Finds an exact string in a file and replaces it, avoiding the need to reproduce the entire file.

- Requires the `old_string` to be unique in the file (unless `replace_all` is set)
- Errors if the string is not found or if `old_string` equals `new_string`
- Preserves the rest of the file exactly

### db_query

Execute SQL queries against configured databases via PDO. Supports MySQL, PostgreSQL, and SQLite.

**Configuration** (`tools.json`):
```json
{
    "db_query": {
        "enabled": true,
        "read_only": false,
        "max_rows": 1000,
        "connections": {
            "default": {
                "driver": "sqlite",
                "database": "writable/agent/data/agent.db"
            },
            "production": {
                "driver": "mysql",
                "host": "127.0.0.1",
                "port": 3306,
                "database": "myapp",
                "username": "root",
                "password": ""
            }
        }
    }
}
```

Set `read_only: true` to block INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, and CREATE queries.

### image_generate

Generate images from text prompts. Supports three providers:

- **OpenAI DALL-E** — requires `OPENAI_API_KEY` env var
- **Stable Diffusion** — requires a local Stable Diffusion Web UI (Automatic1111) at port 7860
- **ComfyUI** — requires a local ComfyUI instance at port 8188

Images are saved to `writable/agent/generated/images/` by default.

### cron_schedule

Manage scheduled tasks stored as JSON files. Supports simple intervals (`5m`, `1h`, `1d`) and cron expressions (`*/5 * * * *`).

Tasks can be agent prompts (sent to the LLM) or shell commands.

### http_request

Full HTTP client that goes beyond `http_get`:

- All HTTP methods (POST, PUT, PATCH, DELETE, HEAD, OPTIONS)
- Custom headers
- JSON body (auto-sets Content-Type)
- Form-encoded body
- Raw string body
- Response header parsing
- JSON response auto-detection
- Configurable host blocking

### archive_extract

Create and extract ZIP, tar.gz, and tar.bz2 archives:

- Auto-detects format from file extension
- Maximum extraction size limit (default 100MB) for safety
- Recursively adds directories when creating archives

### process_manager

Lifecycle management for background processes:

- **start** — launches a command in the background, captures stdout/stderr to log files
- **stop** — sends SIGTERM (graceful), then SIGKILL if needed
- **status** — check if a PID is still running
- **list** — show all managed processes and their state
- **tail** — read recent stdout/stderr output
- **kill_all** — SIGTERM all managed processes

### notification_send

Send notifications through five channels:

| Channel | Configuration Required |
|---------|----------------------|
| `desktop` | None (uses native OS notifications) |
| `slack` | `SLACK_WEBHOOK_URL` env var |
| `discord` | `DISCORD_WEBHOOK_URL` env var |
| `telegram` | `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` env vars |
| `email` | SMTP config in `tools.json` |

Desktop notifications work on macOS (osascript), Linux (notify-send), and Windows (PowerShell toast).

### diff_review

Structured diff analysis with four modes:

- **files** — compare two files on disk
- **git** — compare two git refs
- **staged** — show git staged changes
- **working** — show unstaged working tree changes

Returns parsed hunks with addition/deletion counts per hunk.

## Tool Configuration

Tools are configured in `writable/agent/config/tools.json`. Each tool has at minimum:

```json
{
    "tool_name": {
        "enabled": true,
        "description": "What the tool does",
        "timeout": 10
    }
}
```

Some tools have additional configuration (database connections, API keys, allowed commands, etc.). See tool-specific sections above.

## Tool Execution Lifecycle

1. **Discovery** — The ToolRegistry loads all tool classes and their configs at startup.
2. **Declaration** — When sending a prompt to a provider, available tools are declared in the request using each tool's name, description, and parameter schema.
3. **Invocation** — When the AI model responds with a tool call, the ToolRegistry looks up the tool by name.
4. **Validation** — Required parameters are checked via `requireArgs()`.
5. **Execution** — The tool's `execute()` method is called with the provided arguments.
6. **Result** — The tool returns a result array (success or error) with timestamp.
7. **Feedback** — The result is sent back to the model as a tool result message for the next turn.

## Tool Testing

PHPClaw includes a smoke test runner for all tools:

```bash
# Test all tools
php spark agent:tools:test

# Test a specific tool
php spark agent:tools:test file_read

# Verbose output
php spark agent:tools:test --verbose
```

Each tool gets these tests:
- **schema** — verifies `getInputSchema()` returns a valid array
- **enabled** — verifies the tool reports itself as enabled
- **Functional tests** — tool-specific, safe operations in a sandboxed temp directory

Tests that require external services (API keys, databases, running servers) are automatically skipped.

## Adding Custom Tools

### Scaffold a New Tool

```bash
php spark agent:tool:scaffold MyCustomTool
```

This generates a tool class from the template at `writable/agent/templates/tool.php.tpl`.

### Tool Class Structure

```php
<?php

namespace App\Libraries\Tools;

class MyCustomTool extends BaseTool
{
    protected string $name = 'my_custom_tool';
    protected string $description = 'Description of what this tool does';

    public function getInputSchema(): array
    {
        return [
            'param_name' => [
                'type' => 'string',
                'description' => 'What this parameter does',
                'required' => true,
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['param_name'])) return $err;

        // Implementation here

        return $this->success($result, 'Operation completed');
    }
}
```

### Register the Tool

1. Add the class to `ToolRegistry::$builtinTools` in `app/Libraries/Service/ToolRegistry.php`
2. Add configuration to `writable/agent/config/tools.json`
3. Optionally add to module tool lists in `writable/agent/config/modules.json`

## Result Format

### Success

```json
{
    "success": true,
    "tool": "tool_name",
    "message": "OK",
    "data": { ... },
    "timestamp": "2026-03-18T12:00:00+00:00"
}
```

### Error

```json
{
    "success": false,
    "tool": "tool_name",
    "error": "What went wrong",
    "error_code": 0,
    "data": null,
    "timestamp": "2026-03-18T12:00:00+00:00"
}
```
