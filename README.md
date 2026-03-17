# PHPClaw - Terminal-First Multi-Model AI Agent Shell

PHPClaw is a compact, open-source, terminal-first, multi-model AI agent shell built in PHP with CodeIgniter 4. It provides a self-hosted CLI-native runtime for interacting with multiple AI model providers, managing long-lived sessions, executing background tasks, and maintaining persistent memory -- all from the terminal.

## Goals

- **Self-hosted CLI-native agent runtime** -- runs on your machine, no cloud dependency required.
- **Long-lived service** -- operates as a persistent background service with a heartbeat loop.
- **Interactive terminal** -- full REPL-style chat interface with slash commands.
- **Multiple model providers** -- route requests to Ollama, OpenLLM, ChatGPT, Claude Code, or custom providers.
- **Background tasks** -- queue, monitor, and manage asynchronous task execution.
- **Modular tools** -- extensible tool system with scaffolding support.
- **File-based storage** -- no database required; everything is stored in structured files.
- **Memory with compaction** -- layered memory system with automatic summarization and compaction.
- **Open-source extensibility** -- scaffold new tools, providers, and modules with built-in commands.

## Non-Goals

- Not a web SaaS platform.
- Not an enterprise orchestration framework.
- Not a distributed microservices architecture.
- Not a web dashboard or GUI application.
- Not a multi-tenant system.

## Architecture Overview

PHPClaw is organized into several core layers:

- **Providers** -- adapters for AI model backends (Ollama, OpenLLM, ChatGPT, Claude Code).
- **Tools** -- callable actions the agent can invoke (file operations, shell, web fetch, etc.).
- **Modules** -- role-based configurations that combine a prompt, tools, and model routing.
- **Sessions** -- persistent conversation state with transcript logging.
- **Tasks** -- background job queue with step tracking and progress reporting.
- **Memory** -- layered note extraction, compaction, and summary generation.
- **Cache** -- response and artifact caching with TTL and pruning.
- **Service Loop** -- long-running process that handles tasks, maintenance, and health checks.

## Requirements

- PHP 8.2 or later
- CodeIgniter 4
- Linux CLI environment
- PHP `curl` extension enabled

## Quick Start

```bash
# Clone the repository
git clone https://github.com/yourorg/phpclaw.git
cd phpclaw/agent

# Copy environment config
cp .env.example .env

# Configure your providers
# Edit writable/agent/config/providers.json

# Start an interactive chat session
php spark agent:chat
```

## CLI Commands

### Core

| Command | Description |
|---|---|
| `agent:chat` | Start an interactive chat session (REPL) |
| `agent:config` | Display or validate current configuration |
| `agent:serve` | Start the long-running service loop |
| `agent:status` | Show service status and health information |

### Providers and Models

| Command | Description |
|---|---|
| `agent:providers` | List configured providers and their status |
| `agent:models` | List available models across all providers |
| `agent:roles` | List defined roles and their model assignments |
| `agent:modules` | List available modules and their configuration |

### Sessions

| Command | Description |
|---|---|
| `agent:sessions` | List all stored sessions |
| `agent:session:show` | Display details and transcript of a specific session |

### Tasks

| Command | Description |
|---|---|
| `agent:tasks` | List all tasks and their current status |
| `agent:task:show` | Display details of a specific task |
| `agent:task:tail` | Follow live output of a running task |
| `agent:task:cancel` | Cancel a queued or running task |

### Memory

| Command | Description |
|---|---|
| `agent:memory:show` | Display memory contents for a scope |
| `agent:memory:compact` | Run compaction on memory notes |
| `agent:maintain` | Run all maintenance routines (memory, cache, logs) |

### Cache

| Command | Description |
|---|---|
| `agent:cache:status` | Show cache usage statistics |
| `agent:cache:clear` | Clear all cached data |
| `agent:cache:prune` | Remove expired cache entries |

### Scaffolding

| Command | Description |
|---|---|
| `agent:tools` | List all registered tools |
| `agent:tool:scaffold` | Generate a new tool from template |
| `agent:provider:scaffold` | Generate a new provider from template |

## Interactive Chat Slash Commands

While in an `agent:chat` session, the following slash commands are available:

| Command | Description |
|---|---|
| `/help` | Show available slash commands |
| `/provider` | Switch or display the active provider |
| `/model` | Switch or display the active model |
| `/role` | Switch or display the active role |
| `/module` | Switch or display the active module |
| `/tools` | List available tools |
| `/tasks` | List background tasks |
| `/memory` | Show memory for current session |
| `/status` | Display session and service status |
| `/debug` | Toggle debug output |
| `/save` | Save current session transcript |
| `/exit` | End the chat session |

## Storage Layout

All runtime data lives under `writable/agent/`:

```
writable/agent/
├── config/
│   ├── providers.json
│   ├── roles.json
│   ├── modules.json
│   └── service.json
├── sessions/
│   ├── index.json
│   └── <session-id>/
│       ├── session.json
│       ├── transcript.ndjson
│       └── memory/
├── tasks/
│   ├── index.json
│   └── <task-id>/
│       ├── task.json
│       ├── steps.ndjson
│       ├── progress.ndjson
│       ├── messages.ndjson
│       ├── output.md
│       ├── artifacts/
│       └── checkpoints/
├── memory/
│   ├── global/
│   │   ├── notes.ndjson
│   │   ├── summary.md
│   │   └── compacted/
│   ├── sessions/
│   ├── modules/
│   └── tasks/
├── cache/
│   ├── index.json
│   └── entries/
├── logs/
│   ├── service.log
│   └── errors.log
├── prompts/
│   ├── system/
│   └── modules/
└── templates/
    ├── tool.php.tpl
    └── provider.php.tpl
```

## Provider Setup

### Ollama (Local)

Install Ollama and pull a model:

```bash
ollama pull llama3
```

Configure in `writable/agent/config/providers.json`:

```json
{
  "ollama": {
    "type": "ollama",
    "base_url": "http://localhost:11434",
    "enabled": true
  }
}
```

### OpenLLM

Configure with your OpenLLM-compatible endpoint:

```json
{
  "openllm": {
    "type": "openllm",
    "base_url": "http://localhost:3000",
    "api_key": "your-key",
    "enabled": true
  }
}
```

### ChatGPT

Requires an OpenAI API key:

```json
{
  "chatgpt": {
    "type": "chatgpt",
    "api_key": "sk-...",
    "enabled": true
  }
}
```

Note: ChatGPT uses externally supplied OAuth credentials. PHPClaw does not manage OpenAI account creation or billing.

### Claude Code

Uses the Claude Code CLI for integration:

```json
{
  "claude": {
    "type": "claude_code",
    "enabled": true
  }
}
```

Ensure the `claude` CLI is installed and authenticated.

## Adding Custom Tools and Providers

Use the built-in scaffold commands to generate new tools and providers from templates:

```bash
# Scaffold a new tool
php spark agent:tool:scaffold MyCustomTool

# Scaffold a new provider
php spark agent:provider:scaffold MyCustomProvider
```

Edit the generated files to implement your logic, then register them in the appropriate config.

## Development Workflow

1. Clone the repository and install dependencies.
2. Copy `.env.example` to `.env` and configure.
3. Set up at least one provider (Ollama is easiest for local development).
4. Run `php spark agent:chat` to test interactively.
5. Use scaffold commands to add tools or providers.
6. Run `php spark agent:serve` to test the service loop.

See `docs/development.md` for detailed development guidance.

## License

MIT License. See [LICENSE](LICENSE) for details.
