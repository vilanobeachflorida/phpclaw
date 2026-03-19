# Tools

## Overview

Tools are callable actions that the agent can invoke during chat and task execution. PHPClaw ships with 34 built-in tools and supports adding custom tools through scaffolding.

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

### Coding & Development

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `project_detect` | Auto-detect project languages, frameworks, test runners, linters, and build systems | `path` |
| `test_runner` | Detect and run tests for any language, returning structured pass/fail results | `action`, `path`, `scope`, `target`, `framework` |
| `lint_check` | Detect and run linters/formatters for any language with structured diagnostics | `action`, `path`, `linter`, `target` |
| `code_symbols` | Language-agnostic code intelligence: find definitions, references, and outlines | `action`, `path`, `symbol`, `kind` |
| `build_runner` | Detect and run build systems, install dependencies, and execute project scripts | `action`, `path`, `tool`, `script_name` |
| `error_parser` | Parse raw error output from any language into structured errors | `action`, `input`, `language` |
| `task_planner` | Create and track multi-step plans for complex tasks with persistent checkpoints | `action`, `plan_id`, `goal`, `steps` |
| `context_manager` | Compress, stash, and recall working context for long coding sessions | `action`, `path`, `stash_name`, `context` |

### Search & Analysis

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `grep_search` | Search file contents with regex patterns | `pattern`, `path`, `recursive`, `max_results` |
| `git_ops` | Structured git operations with parsed JSON output | `operation`, `path`, `ref`, `max_count` |
| `diff_review` | Analyze code diffs with structured per-hunk output | `mode`, `path_a`, `path_b`, `context_lines` |

### Execution Targets

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `exec_target` | Manage execution targets (local, SSH, Docker, K8s) and run commands remotely | `action`, `target`, `command`, `type`, `host` |
| `shell_exec` | Execute shell commands | `command`, `cwd`, `timeout` |
| `system_info` | Get system information | (none) |
| `process_manager` | Start, monitor, and stop background processes | `action`, `command`, `name`, `pid`, `tail_lines` |

### Network & Web

| Tool | Description | Key Parameters |
|------|-------------|----------------|
| `http_get` | Make HTTP GET requests | `url`, `headers`, `timeout` |
| `http_request` | Full HTTP client (all methods, headers, body, auth) | `url`, `method`, `headers`, `body`, `json`, `form` |
| `browser_fetch` | Fetch and parse web pages | `url`, `timeout` |
| `browser_text` | Extract text from web pages | `url`, `timeout` |

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

### project_detect

Scans a directory for marker files and returns a structured profile of the project:

- **Languages** — detected by file extensions and marker files (PHP, JavaScript, Python, Go, Rust, Java, Ruby, Swift, Dart, etc.)
- **Frameworks** — CodeIgniter, Laravel, Next.js, Django, FastAPI, Rails, Flutter, etc.
- **Package managers** — composer, npm, yarn, pnpm, cargo, go mod, pip, poetry, bundler, etc.
- **Test frameworks** — PHPUnit, Jest, Vitest, pytest, cargo test, go test, RSpec, JUnit, etc.
- **Linters** — PHPStan, ESLint, Prettier, Ruff, golangci-lint, Clippy, RuboCop, etc.
- **Build systems** — Make, Docker, Vite, Webpack, CMake, etc.

```
{"path": "/home/user/my-project"}
```

### test_runner

Universal test execution with structured output. Works with any language:

- **detect** — find test framework(s) in the project
- **run** — execute tests with scope control (all, file, method, suite)
- **parse** — parse raw test output into structured results

Parses JUnit XML output when available, falls back to regex parsing of stdout for PHPUnit, Jest, pytest, cargo test, go test, etc.

```
# Detect test framework
{"action": "detect", "path": "/my-project"}

# Run all tests
{"action": "run"}

# Run a specific test file
{"action": "run", "scope": "file", "target": "tests/UserTest.php"}

# Run a specific test method
{"action": "run", "scope": "method", "target": "test_user_creation"}
```

### lint_check

Universal linting and formatting with structured diagnostics:

- **detect** — identify installed linters from config files
- **run** — execute linter, return structured diagnostics (file, line, message, severity)
- **fix** — auto-fix where supported

Supports JSON output parsing for ESLint, PHPStan, Ruff, Biome, and others. Falls back to `file:line:col: message` regex parsing.

```
# Detect linters
{"action": "detect"}

# Run linter
{"action": "run", "linter": "eslint"}

# Auto-fix
{"action": "fix", "linter": "prettier"}
```

### code_symbols

Language-agnostic code intelligence via universal-ctags (with regex fallback):

- **index** — build/update symbol index for the project
- **find_definition** — locate where a symbol is defined
- **find_references** — find all usages of a symbol (via grep)
- **list_symbols** — list symbols in a file (functions, classes, types)
- **outline** — structural overview of a file grouped by kind

Uses `ctags` when installed, falls back to regex patterns that work for all major languages.

```
# Build index
{"action": "index", "path": "/my-project"}

# Find where a function is defined
{"action": "find_definition", "symbol": "UserService", "kind": "class"}

# Get file outline
{"action": "outline", "path": "src/controllers/UserController.php"}
```

### build_runner

Universal build system and dependency management:

- **detect** — identify build tools and available scripts
- **build** — execute the build command
- **run** — start the project (dev server, main script)
- **deps** — install or update dependencies
- **clean** — run clean/reset commands
- **script** — run a named script (npm run X, make X, composer X, etc.)

Auto-detects: npm, yarn, pnpm, bun, composer, cargo, go, pip, bundler, make, task, just, docker, gradle, maven, flutter, mix, and more.

```
# Detect build tools
{"action": "detect"}

# Install dependencies
{"action": "deps"}

# Run a script
{"action": "script", "script_name": "dev"}

# Build the project
{"action": "build"}
```

### error_parser

Parses raw error output from any language into structured errors:

- Auto-detects the language from error patterns
- Extracts file, line, column, message, severity, and error type
- Parses stack traces (PHP, Python, Node.js, Java, Ruby, Go)
- Detects fatal errors, panics, and segfaults
- Supports PHP, Python, Node.js, Go, Rust, Java/Kotlin, Ruby, C/C++

```
# Parse raw error output
{"action": "parse", "input": "PHP Fatal error: Call to undefined function foo() in /app/test.php on line 42"}

# Parse a log file
{"action": "parse_file", "path": "/var/log/app.log", "tail": 100}
```

### task_planner

Persistent multi-step task planning with checkpoints:

- **create** — create a plan with a goal and steps
- **update_step** — mark steps as pending/in_progress/done/failed/blocked/skipped
- **checkpoint** — save progress (files changed, step status)
- **resume** — reload plan state after context reset
- **complete** / **delete** — lifecycle management

Plans are stored as JSON in `writable/agent/plans/` and persist across sessions.

```
# Create a plan
{"action": "create", "goal": "Migrate auth to JWT", "steps": ["Add JWT library", "Create token service", "Update middleware", "Write tests"]}

# Update a step
{"action": "update_step", "plan_id": "20260318_...", "step_index": 0, "status": "done"}

# Save checkpoint
{"action": "checkpoint", "plan_id": "20260318_...", "files_changed": ["src/auth/jwt.php"]}
```

### context_manager

Context compression and stashing for long sessions:

- **summarize** — compress a file or directory into key facts (imports, exports, classes, functions, file counts)
- **stash** — save working context (task, files, notes) before switching tasks
- **recall** — restore stashed context
- **project_brief** — one-call summary of the entire project (stack, tree, git info, README excerpt)

```
# Summarize a file
{"action": "summarize", "path": "src/services/UserService.php"}

# Stash current context
{"action": "stash", "stash_name": "auth-refactor", "task": "Refactoring auth middleware", "files": ["src/auth.php"]}

# Get project overview
{"action": "project_brief", "max_depth": 2}
```

### exec_target

Execution environment manager for running commands on local, remote, or containerized hosts:

- **list** — show configured targets
- **set** — switch active target
- **status** — probe connectivity, OS, available tools
- **register** / **remove** — manage targets
- **exec** — run a command on a target
- **upload** / **download** — transfer files to/from targets

Supported target types: `local`, `ssh`, `docker`, `docker_compose`, `kubernetes`

```
# Register an SSH target
{"action": "register", "target": "staging", "type": "ssh", "host": "staging.example.com", "user": "deploy", "key": "~/.ssh/id_ed25519"}

# Run a command on a Docker container
{"action": "exec", "target": "app", "command": "php artisan migrate"}

# Upload a file
{"action": "upload", "local_path": "./deploy.sh", "remote_path": "/tmp/deploy.sh"}
```

### git_ops

Runs structured git operations without exposing raw shell access. Returns parsed JSON output.

**Supported operations:** `status`, `diff`, `log`, `blame`, `branch`, `show`, `stash_list`, `tag`

```
{"operation": "status"}
{"operation": "log", "max_count": 10}
{"operation": "diff", "ref": "HEAD~3"}
```

### code_patch

Safer alternative to `file_write` for modifications. Finds an exact string in a file and replaces it.

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

### http_request

Full HTTP client: all methods (POST, PUT, PATCH, DELETE, HEAD, OPTIONS), custom headers, JSON/form/raw body, response header parsing, host blocking.

### archive_extract

Create and extract ZIP, tar.gz, and tar.bz2 archives. Auto-detects format. Maximum extraction size limit (default 100MB).

### process_manager

Background process lifecycle management: start, stop, status, list, tail output, kill_all.

### notification_send

Send notifications through five channels:

| Channel | Configuration Required |
|---------|----------------------|
| `desktop` | None (uses native OS notifications) |
| `slack` | `SLACK_WEBHOOK_URL` env var |
| `discord` | `DISCORD_WEBHOOK_URL` env var |
| `telegram` | `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` env vars |
| `email` | SMTP config in `tools.json` |

### diff_review

Structured diff analysis with four modes: files (compare two files), git (compare refs), staged, working.

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
