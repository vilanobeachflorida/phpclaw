# Architecture

## System Layers

PHPClaw follows a layered architecture with clear separation of concerns:

```
┌─────────────────────────────────────┐
│           CLI Interface             │
│  (Spark Commands, Chat REPL)        │
├─────────────────────────────────────┤
│           Commands Layer            │
│  (CodeIgniter Spark Commands)       │
├─────────────────────────────────────┤
│          Libraries Layer            │
│  (Core managers, router, registry)  │
├─────────────────────────────────────┤
│          Storage Layer              │
│  (FileStorage, JSON, NDJSON, MD)    │
└─────────────────────────────────────┘
```

### CLI Interface

The top layer is the user-facing CLI. This includes Spark commands (`agent:chat`, `agent:serve`, etc.) and the interactive chat REPL with its slash commands.

### Commands Layer

CodeIgniter 4 Spark commands that parse arguments, invoke library methods, and format output for the terminal. Each command is a thin wrapper that delegates to the libraries layer.

### Libraries Layer

The core logic of PHPClaw. Managers, routers, and registries that coordinate providers, tools, sessions, tasks, and memory.

### Storage Layer

All persistence is handled through file-based storage. No database is used. See `storage.md` for full details.

## Core Components

### FileStorage

Low-level file I/O abstraction. Handles reading, writing, appending, and locking for JSON, NDJSON, Markdown, and plain text files. All other components use FileStorage rather than direct filesystem calls.

### ConfigLoader

Reads and validates configuration from `writable/agent/config/`. Supports providers, roles, modules, and service configuration. Provides typed access to config values with defaults.

### SessionManager

Creates, loads, and manages chat sessions. Each session has a unique ID, metadata in `session.json`, and an append-only transcript in `transcript.ndjson`. Sessions track the active provider, model, role, and module.

### TaskManager

Manages the background task queue. Handles task creation, status transitions (queued -> running -> completed/failed/cancelled), step logging, progress tracking, and artifact storage.

### MemoryManager

Implements the layered memory system. Ingests transcript entries, extracts notes, runs compaction, and generates summaries. Operates across global, session, module, and task scopes.

### CacheManager

Manages response and artifact caching with TTL-based expiration. Supports status reporting, targeted clearing, and pruning of expired entries.

### ModelRouter

Routes requests to the appropriate provider and model based on role assignments. Consults the role configuration to determine which provider/model handles a given role, with fallback chains for resilience.

### ProviderManager

Manages provider instances. Handles provider registration, health checks, model listing, and lifecycle. Each provider implements a common interface for sending prompts and receiving responses.

### ToolRegistry

Registers and manages available tools. Tools are discovered, validated, and made available for invocation during chat and task execution. Handles tool execution lifecycle and result formatting.

### ServiceLoop

The long-running process that powers background operation. Continuously cycles through task processing, heartbeat checks, maintenance routines, and health monitoring.

## Data Flow

### Interactive Chat

```
User Input
  │
  ▼
Chat REPL (parses input, checks for slash commands)
  │
  ▼
ModelRouter (resolves role -> provider + model)
  │
  ▼
Provider (sends prompt to AI backend, receives response)
  │
  ▼
Tool Execution (if response contains tool calls)
  │
  ▼
Response Display (formatted output to terminal)
  │
  ▼
Transcript (appended to session transcript.ndjson)
  │
  ▼
Memory (notes extracted from transcript)
```

### Service Loop

```
Start
  │
  ▼
Load Configuration
  │
  ▼
┌──────────────────────────┐
│  Check Task Queue        │◄──────┐
│  Process Pending Tasks   │       │
│  Run Maintenance         │       │
│  Heartbeat Check         │       │
│  Health Checks           │       │
│  Sleep (interval)        │───────┘
└──────────────────────────┘
  │
  ▼
Shutdown (on signal)
```

## Design Principles

### File-Based Everything

All state is stored in files. No database, no Redis, no external state stores. This keeps the system self-contained, inspectable, and easy to back up or move.

### Template-Driven Extensibility

New tools and providers are created from templates using scaffold commands. This ensures consistent structure and reduces boilerplate.

### Explicit Control Flow

No magic routing or hidden event buses. The flow from command to library to storage is direct and traceable. Each component has clear responsibilities.

### Append-Only Logs

Transcripts, step logs, and progress logs are append-only NDJSON files. Data is never modified in place. This provides a complete audit trail and prevents data loss.

### Derived Summaries

Summaries and compacted artifacts are derived from raw data. The raw logs are the source of truth. Summaries can be regenerated at any time from the underlying data.
