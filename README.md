# PHPClaw

Self-hosted AI agent that you can run anywhere and access from anything. Use it from the terminal, or talk to it over HTTP from any app, script, or device on your network. Mix and match any combination of local and cloud LLM providers — run Ollama on your own hardware, fall back to Claude or GPT when you need more power, or use both at the same time with role-based routing.

```
  ╔══════════════════════════════════════════════╗
  ║           PHPClaw Agent Shell                ║
  ║                 v0.2.0                       ║
  ╚══════════════════════════════════════════════╝

  reasoning:ollama ❯ fetch the top 5 hacker news stories and summarize them
  ◆ Working...
    ✓ browser_fetch
    ✓ browser_fetch
    ✓ browser_fetch

  Here are today's top Hacker News stories...

  ─ 4.2k in · 890 out · 3 tools · 5.1s ─
```

PHPClaw is an open-source, self-hosted AI agent shell built in PHP. Deploy it on a home server, a VPS, or any machine — then interact with it from a terminal, a browser, a mobile app, or anything that can make HTTP requests. Connect Ollama, LM Studio, OpenAI, Claude, or any OpenAI-compatible endpoint as providers. Route different tasks to different models: local models for quick lookups, cloud models for heavy reasoning, or any combination in between. The agent loops autonomously — calling tools, reading results, and continuing until the task is done. No vendor lock-in, no subscriptions required, your data stays on your machine.

## Quick Start

```bash
git clone https://github.com/sergiozlobin/phpclaw.git
cd phpclaw/agent

# Install dependencies
composer install

# Run the setup wizard
php spark agent:setup

# Start chatting
php spark agent:chat
```

The setup wizard walks you through everything: environment check, directory setup, provider configuration (with interactive menus), and validation.

## Requirements

- PHP 8.2+
- `curl`, `json`, `mbstring` extensions
- Composer
- At least one LLM provider (see below)

Optional but recommended:
- `readline` extension (better chat input with history)
- `pcntl` extension (graceful shutdown / signal handling)

## Supported Providers

| Provider | Type | Auth |
|----------|------|------|
| **Ollama** | Local | None (runs on your machine) |
| **LM Studio** | Local | None |
| **OpenAI / ChatGPT** | Cloud | API key |
| **Claude API** | Cloud | API key (pay per token) |
| **Claude OAuth** | Cloud | Setup token (uses your subscription) |
| **Claude Code CLI** | Local | Claude Code must be installed |
| **OpenLLM** | Any | API key (OpenAI-compatible endpoint) |

### Setting Up a Provider

The setup wizard handles this interactively:

```bash
php spark agent:setup
```

Or configure manually in `writable/agent/config/providers.json`.

**Ollama (easiest for local)**
```bash
# Install Ollama: https://ollama.ai
ollama pull llama3
# PHPClaw connects to http://localhost:11434 by default
```

**OpenAI / ChatGPT**
```bash
# Get API key from https://platform.openai.com/api-keys
# Set in .env:
OPENAI_API_KEY=sk-...
```

**Claude API**
```bash
# Get API key from https://console.anthropic.com/settings/keys
# Set in .env:
ANTHROPIC_API_KEY=sk-ant-...
```

**Claude OAuth (use your subscription)**
```bash
# Generates a setup token from your Claude Pro/Max subscription:
claude setup-token
# Paste the token during setup wizard
```

## How It Works

PHPClaw runs an agent loop:

1. You type a message
2. The agent sends it to your LLM with a system prompt describing available tools
3. The LLM responds with text and/or tool calls
4. PHPClaw executes the tools (read files, fetch URLs, run commands, etc.)
5. Results are fed back to the LLM
6. The loop continues until the LLM responds with no tool calls (task complete)

There's no iteration limit. The agent runs until it's done. If it gets stuck (same calls repeated, or all tools failing), it asks you what to do: continue, stop, or give new instructions.

## Built-in Tools

PHPClaw ships with 35 built-in tools across several categories:

### File Operations

| Tool | Description |
|------|-------------|
| `file_read` | Read file contents |
| `file_write` | Write content to a file |
| `file_append` | Append to a file |
| `dir_list` | List directory contents |
| `mkdir` | Create directories |
| `move_file` | Move or rename files |
| `delete_file` | Delete files |
| `code_patch` | Surgical code editing via exact string replacement |
| `archive_extract` | Create and extract archives (ZIP, tar.gz, tar.bz2) |

### Coding & Development

| Tool | Description |
|------|-------------|
| `project_detect` | Auto-detect project languages, frameworks, test runners, linters, and build systems |
| `test_runner` | Detect and run tests for any language, returning structured pass/fail results |
| `lint_check` | Detect and run linters/formatters for any language with structured diagnostics |
| `code_symbols` | Language-agnostic code intelligence: find definitions, references, and outlines (via ctags or regex) |
| `build_runner` | Detect and run build systems, install dependencies, and execute project scripts |
| `error_parser` | Parse raw error output from any language into structured errors with file, line, and message |
| `task_planner` | Create and track multi-step plans for complex tasks with persistent checkpoints |
| `context_manager` | Compress, stash, and recall working context for long coding sessions |

### Search & Analysis

| Tool | Description |
|------|-------------|
| `grep_search` | Search file contents with patterns |
| `git_ops` | Structured git operations (status, diff, log, blame, branch) |
| `diff_review` | Analyze code diffs with structured per-hunk output |

### Execution Targets

| Tool | Description |
|------|-------------|
| `exec_target` | Manage execution targets (local, SSH, Docker, K8s) and run commands remotely |
| `shell_exec` | Execute shell commands |
| `system_info` | Get system information |
| `process_manager` | Start, monitor, and stop background processes |

### Network & Web

| Tool | Description |
|------|-------------|
| `http_get` | Make HTTP GET requests (server-side/headless) |
| `http_request` | Full HTTP client (all methods, headers, body, auth) |
| `browser_fetch` | Fetch and parse web pages (server-side/headless) |
| `browser_text` | Extract text from web pages (server-side/headless) |
| `browser_control` | Control the user's real Chrome browser via the PHPClaw extension |

### Data & Integration

| Tool | Description |
|------|-------------|
| `db_query` | Execute SQL queries (MySQL, PostgreSQL, SQLite) |
| `image_generate` | Generate images from text prompts (DALL-E, Stable Diffusion, ComfyUI) |
| `cron_schedule` | Create and manage scheduled recurring tasks |
| `notification_send` | Send notifications (email, Slack, Discord, Telegram, desktop) |

## Tool Testing

PHPClaw includes a built-in smoke test runner that validates every registered tool in a safe sandbox environment. No files outside the sandbox are touched, no external services are called unless credentials are configured.

```bash
# Test all tools
php spark agent:tools:test

# Test a specific tool
php spark agent:tools:test git_ops

# Verbose output with test details
php spark agent:tools:test --verbose
```

Each tool is tested for:
- **Schema validation** — tool defines a valid input schema
- **Enabled check** — tool reports itself as enabled
- **Argument validation** — tool correctly errors on missing required args
- **Functional test** — tool performs a safe, non-destructive operation in a sandboxed temp directory

Tests that require external services (API keys, running servers) are automatically skipped if the service isn't available.

```
  ╭─ Tool Smoke Tests ──────────────────────────────────────╮
  │                                                          │
  │  PASS  file_read::schema          3 parameters defined   │
  │  PASS  file_read::read_file       read sandbox file      │
  │  PASS  git_ops::status            branch: main           │
  │  PASS  test_runner::detect        1 framework(s) detected│
  │  PASS  error_parser::parse_php    1 error(s) parsed      │
  │  SKIP  image_generate::api_check  requires external API  │
  │  ...                                                     │
  │                                                          │
  │  128 passed, 0 failed, 1 skipped out of 130 test cases   │
  │  (34 tools, ~3-4 tests each)                             │
  ╰──────────────────────────────────────────────────────────╯
```

## Chat Commands

While in `agent:chat`, use slash commands:

| Command | Description |
|---------|-------------|
| `/help` | Show available commands |
| `/exit` | Exit chat |
| `/usage` | Token usage and cost breakdown |
| `/provider` | Show active providers |
| `/model` | Show current model routing |
| `/role [name]` | Show or set current role |
| `/module [name]` | Show or set current module |
| `/tools` | List available tools |
| `/tasks` | Show active tasks |
| `/memory` | Show memory stats |
| `/status` | Show system status |
| `/debug` | Toggle debug mode (shows per-request tokens) |

## Smart Module Routing

PHPClaw uses the LLM itself to classify your request and route it to the best module — no manual switching needed. Before your main request is processed, a fast, lightweight classification call asks the model: "Is this a question, a coding task, a web fetch, a planning request, or a summarization?" The answer determines which module handles it.

| You say... | Auto-routes to |
|-----------|---------------|
| "create a PHP website for my business" | **coding** — full dev toolkit (19 tools) |
| "fetch https://example.com" | **browser** — web fetching tools |
| "plan out the deployment process" | **planner** — task decomposition |
| "summarize this README" | **summarizer** — content compression |
| "what is dependency injection?" | **reasoning** — stays on default |
| "how do I make a bash script?" | **reasoning** — questions stay on default |
| "build a REST API with Express" | **coding** — imperative = action |

The LLM understands intent, not just keywords — "how do I build an API?" stays on reasoning (it's a question), while "build me an API" switches to coding (it's a command). If the LLM classification call fails, a regex fallback handles common patterns. Auto-routing only activates on the default `reasoning` module; manual `/module` selection is always respected.

### Available Modules

| Module | Tools | Purpose |
|--------|-------|---------|
| **reasoning** | all | Deep analysis, Q&A (default) |
| **coding** | 19 | Code generation, refactoring, testing, deployment |
| **browser** | 5 | Real browser control, web fetching, content extraction |
| **planner** | 8 | Task decomposition with browser and web access |
| **summarizer** | 1 | Content summarization |
| **tool_router** | 35 | Catch-all with access to every tool |

### Small Model Enhancements

PHPClaw includes several features designed to get better results from smaller local models (7B–14B):

- **Continuation nudging** — when a model narrates plans instead of executing them, the agent nudges it to make tool calls
- **Hallucination detection** — catches when a model claims it created files that don't actually exist
- **Tool-based self-review** — after task completion, verifies files were actually written using `dir_list`
- **Progress tracking** — reminds the model of the original request and completed actions between iterations

## Token Usage Tracking

PHPClaw tracks token usage and estimates costs, similar to Claude Code:

```
─ 847 in · 234 out · $0.004 · 2 tools · 1.2s ─
```

Use `/usage` for a full session breakdown with per-model costs. Local providers (Ollama, LM Studio) show $0.00.

## CLI Commands

```bash
# Core
php spark agent:chat              # Interactive chat (main interface)
php spark agent:setup             # Setup wizard
php spark agent:serve             # Start API server + background service
php spark agent:status            # System status

# Providers & Models
php spark agent:providers         # List providers and health
php spark agent:models            # List available models
php spark agent:roles             # List model roles
php spark agent:modules           # List modules

# API
php spark agent:api:token          # Generate or show the API token
php spark agent:api:serve          # Start the API HTTP server

# Authentication
php spark agent:auth status       # Show auth status
php spark agent:auth login <p>    # OAuth/setup-token login
php spark agent:auth token <p>    # Paste token manually
php spark agent:auth refresh <p>  # Force token refresh
php spark agent:auth revoke <p>   # Remove stored token

# Sessions
php spark agent:sessions          # List sessions
php spark agent:session:show <id> # Show session transcript

# Tasks
php spark agent:tasks             # List tasks
php spark agent:task:show <id>    # Show task details
php spark agent:task:tail <id>    # Follow task progress
php spark agent:task:cancel <id>  # Cancel a task

# Memory & Cache
php spark agent:memory:show       # Memory stats and notes
php spark agent:memory:compact    # Run memory compaction
php spark agent:cache:status      # Cache statistics
php spark agent:cache:clear       # Clear all cache
php spark agent:cache:prune       # Prune expired entries
php spark agent:maintain          # Run all maintenance

# Tools
php spark agent:tools             # List tools
php spark agent:tools:test        # Run smoke tests on all tools
php spark agent:tools:test <name> # Test a single tool
php spark agent:tools:test --verbose # Verbose test output
php spark agent:tool:scaffold     # Generate new tool from template
php spark agent:provider:scaffold # Generate new provider from template

# Workspace
php spark agent:workspace:clean        # Clean workspace output
php spark agent:workspace:clean --all  # Clean all user-generated data

# Configuration
php spark agent:config            # List config files
php spark agent:config <name>     # Show config with syntax highlighting
php spark agent:config:reset      # Reset all config to shipped defaults
php spark agent:config:reset --file providers  # Reset a single config file
```

## Architecture

```
┌─────────────────────────────────────────────────┐
│                   User Input                     │
│        (Terminal / CLI REPL / REST API)           │
└──────────────────────┬──────────────────────────┘
                       │
              ┌────────▼────────┐
              │  Agent Executor  │ ← loops until done
              │   (core loop)   │
              └──┬──────────┬───┘
                 │          │
        ┌────────▼──┐  ┌───▼────────┐
        │  Model     │  │   Tool     │
        │  Router    │  │  Registry  │
        └────┬───────┘  └───┬────────┘
             │              │
     ┌───────▼───────┐  ┌──▼──────────────┐  ┌──────────────────┐
     │  Providers     │  │  Tools           │  │  Chrome Extension │
     │  ┌──────────┐  │  │  file_read       │  │  ┌──────────────┐│
     │  │ Ollama   │  │  │  file_write      │  │  │Content Script ││
     │  │ LMStudio │  │  │  shell_exec      │  │  │(polls + DOM) ││
     │  │ ChatGPT  │  │  │  browser_control ◄──┤  └──────┬───────┘│
     │  │ Claude   │  │  │  grep_search     │  │  ┌──────▼───────┐│
     │  │ OpenLLM  │  │  │  ...35 total     │  │  │Service Worker ││
     │  └──────────┘  │  └─────────────────┘  │  │(tab ops only) ││
     └────────────────┘                        │  └──────────────┘│
                                               └──────────────────┘
```

**Providers** are LLM backends. Each has an adapter that normalizes the API.

**Roles** map tasks to providers. "reasoning" might go to Ollama, "coding" to Claude.

**Modules** combine a role + prompt + tool set. The "coding" module enables file/shell tools and uses a code-focused prompt.

**Sessions** persist conversation history, transcripts, and tool events.

**Memory** extracts key information across sessions with automatic compaction.

## Storage Layout

All runtime data is in `writable/agent/`:

```
writable/agent/
├── config/          # providers.json, roles.json, modules.json, tools.json
├── workspace/       # ★ Agent output — projects, websites, generated code
├── sessions/        # Conversation history and transcripts
├── tasks/           # Background task queue and progress
├── plans/           # Task planner persistent plans
├── context_stash/   # Stashed working contexts
├── memory/          # Global notes, summaries, compacted knowledge
├── cache/           # LLM response and tool caches
├── generated/       # Generated images and media
├── logs/            # Service and error logs
├── prompts/         # System and module prompt templates
├── state/           # Service state, heartbeat, health
├── processes/       # Background process state
├── schedules/       # Cron schedules
├── queues/          # Task queues
└── locks/           # File locks
```

The **workspace/** directory is where the agent puts all project output (websites, apps, scripts). It's git-ignored and can be cleaned with:

```bash
# Clean just the workspace
php spark agent:workspace:clean

# Clean everything (workspace + sessions + memory + plans + cache)
php spark agent:workspace:clean --all

# Factory reset: config + all data
php spark agent:config:reset --yes
```

No database required. Everything is JSON files and NDJSON logs.

## REST API

PHPClaw includes a built-in REST API for interacting with the agent over HTTP. Chat with the agent, maintain sessions, and query system status from any language or tool.

```bash
# Generate a token
php spark agent:api:token

# Start the API server (default: 0.0.0.0:8081)
php spark agent:api:serve

# Open interactive docs in your browser
#   http://localhost:8081/api/docs
```

Send messages and maintain sessions via simple JSON requests:

```bash
# Start a conversation
curl -X POST http://localhost:8081/api/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "Hello! What can you do?"}'

# Continue with the returned session_id
curl -X POST http://localhost:8081/api/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "List files in /tmp", "session_id": "SESSION_ID"}'
```

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/chat` | POST | Send a message, get agent response |
| `/api/sessions` | GET | List all sessions |
| `/api/sessions/:id` | GET | Get session details and messages |
| `/api/sessions/:id/archive` | POST | Archive a session |
| `/api/status` | GET | Health check and system info |
| `/api/docs` | GET | Interactive API documentation (no auth) |

The API server can be enabled/disabled in `writable/agent/config/api.json`. See the full **[API Documentation](docs/api.md)** for configuration, all endpoints, session flow, CORS, and code examples in cURL, Python, JavaScript, and PHP.

## Chrome Browser Control

PHPClaw includes a Chrome extension that lets the agent control your real browser — opening tabs, clicking buttons, filling forms, reading pages, and navigating the web exactly like a human user. The agent sees an accessibility tree of the page (numbered interactive elements) and interacts by reference number, making it reliable across any website.

```
  reasoning:lmstudio ❯ go to medieval-europe.eu, log in as chobani, and get the player list

    ▸ browser
    ✓ browser_control navigate → medieval-europe.eu
    ✓ browser_control snapshot → [20] text field "Username" [21] password field ...
    ✓ browser_control type ref:20 "chobani"
    ✓ browser_control type ref:21 "c83b1"
    ✓ browser_control click ref:22 "Login"
    ✓ browser_control snapshot → logged in, found player list link
    ✓ browser_control click ref:6 "Players"
    ✓ browser_control read_text → player data
    ✓ browser_control release

  The top 5 most recently logged-in players are...
```

### Setting Up the Extension

```bash
# 1. Start the API server
php spark agent:serve

# 2. Install the Chrome extension
#    Open chrome://extensions → Enable Developer Mode → Load Unpacked
#    Select the chrome-extension/ directory

# 3. Connect
#    Click the PHPClaw extension icon → enter your server URL and API token → Connect
#    The badge turns green when connected
```

### How It Works

The extension uses a content-script architecture for reliability:

- **Content script** runs on every page and never gets suspended. It polls the server for commands and executes DOM operations (click, type, read, scroll) directly in the page.
- **Service worker** is a thin helper that only handles Chrome API calls (navigate, new tab, screenshot, cookies). These are instant one-shot calls.
- **Accessibility tree** — when navigating to a page, the extension returns a numbered list of all interactive elements. The agent references elements by number instead of guessing CSS selectors.

### Browser Control Actions

| Action | Description |
|--------|-------------|
| `navigate` | Go to a URL (returns page snapshot with numbered elements) |
| `new_tab` | Open a new tab |
| `snapshot` | Get the accessibility tree of the current page |
| `click` | Click an element by ref number, CSS selector, or text description |
| `type` | Type into a field by ref number, selector, or description |
| `read_text` | Read visible text content from the page |
| `read_html` | Get HTML of the page or an element |
| `scroll` | Scroll up, down, to top, or to bottom |
| `screenshot` | Capture the visible tab |
| `get_links` | Get all links on the page |
| `get_forms` | Get all forms and their fields |
| `fill_form` | Fill multiple form fields at once |
| `submit_form` | Submit a form |
| `smart_login` | Auto-detect login form, fill credentials, and submit |
| `execute_js` | Run arbitrary JavaScript in the page |
| `get_tabs` | List all open tabs |
| `switch_tab` | Switch the controlled tab |
| `close_tab` | Close a tab |
| `wait_for` | Wait for an element to appear |
| `get_cookies` | Get cookies for the current page |
| `go_back` / `go_forward` | Browser history navigation |
| `reload` | Reload the page |
| `release` | Release control of the tab (removes border indicator) |

### Visual Indicators

- **Cyan border + "PHPClaw" label** appears on the controlled tab when the agent is actively working
- **Lightning bolt** in the tab title (e.g. `⚡ Google`) so you can identify the controlled tab from any other tab
- **Badge** on the extension icon: green (connected), orange (connecting), blue (executing command)

## Running as a Service

PHPClaw can run as a background service that includes both the API server and the heartbeat/maintenance loop:

```bash
# Start everything (API server + service loop)
php spark agent:serve

# Start with custom port
php spark agent:serve --port 9000

# Start service loop only (no API server)
php spark agent:serve --no-api

# Or use systemd (Linux)
sudo cp phpclaw.service /etc/systemd/system/
sudo systemctl enable phpclaw
sudo systemctl start phpclaw
```

The service handles health checks, task execution, memory compaction, and cache pruning on configurable intervals. The API server enables the Chrome extension, REST API, and interactive docs.

## Extending PHPClaw

### Add a Custom Tool

```bash
php spark agent:tool:scaffold MyTool
```

This generates a tool class from the template. Implement the `execute()` method and register it in `writable/agent/config/tools.json`.

### Add a Custom Provider

```bash
php spark agent:provider:scaffold MyProvider
```

Implement the `chat()` and `healthCheck()` methods. Register in `writable/agent/config/providers.json`.

### Custom Prompts

Edit prompt templates in `writable/agent/prompts/`:
- `system/default.md` — base system prompt
- `modules/*.md` — per-module prompts (coding, reasoning, browser, etc.)

## Documentation

See the `docs/` directory for detailed documentation:

- [REST API](docs/api.md) — API server, endpoints, authentication, and examples
- [Browser Control](docs/browser-control.md) — Chrome extension setup, architecture, and all actions
- [Architecture](docs/architecture.md) — system design and component overview
- [Providers](docs/providers.md) — provider configuration and custom adapters
- [Tools](docs/tools.md) — tool system, all 34 built-in tools, testing, and custom tool development
- [Modules](docs/modules.md) — role-based module configuration
- [Routing](docs/routing.md) — model routing and fallback chains
- [Memory](docs/memory.md) — memory system, compaction, and summaries
- [Tasks](docs/tasks.md) — background task queue
- [Storage](docs/storage.md) — file storage layout and formats
- [Service](docs/service.md) — background service configuration
- [Development](docs/development.md) — development workflow and guidelines

## License

MIT License. See [LICENSE](LICENSE) for details.
