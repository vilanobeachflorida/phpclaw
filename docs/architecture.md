# Architecture

## System Layers

PHPClaw follows a layered architecture with clear separation of concerns:

```
┌─────────────────────────────────────┐
│          CLI Interface              │
│   (Spark Commands, Chat REPL)       │
├─────────────────────────────────────┤
│          Commands Layer             │
│   (CodeIgniter Spark Commands)      │
├─────────────────────────────────────┤
│          Libraries Layer            │
│   (Core Services & Managers)        │
├─────────────────────────────────────┤
│          Storage Layer              │
│   (File-based JSON/NDJSON/MD)       │
└─────────────────────────────────────┘
```

- **CLI Interface** -- the user-facing layer. Spark commands handle argument parsing and output formatting. The chat REPL provides an interactive session with slash command support.
- **Commands Layer** -- CodeIgniter 4 Spark commands that wire user input to library calls. Each command is a thin controller that delegates to the appropriate library.
- **Libraries Layer** -- the core logic. Managers, routers, registries, and the service loop all live here. Libraries are stateless where possible and operate on the storage layer.
- **Storage Layer** -- all persistent state is stored as files on disk. No database is used. See the [Storage documentation](storage.md) for details.

## Core Components

### FileStorage

Low-level file operations: read/write JSON, append NDJSON lines, manage lock files, ensure directory structure. All other components use FileStorage rather than direct file I/O.

### ConfigLoader

Reads and validates configuration files from `writable/agent/config/`. Merges defaults with user overrides. Provides typed access to configuration values.

### SessionManager

Creates, loads, saves, and lists chat sessions. Each session has a unique ID, metadata in `session.json`, and an append-only transcript in `transcript.ndjson`.

### TaskManager

Manages background task lifecycle: creation, queuing, status transitions, progress tracking, and cancellation. Tasks are stored as directories with structured files.

### MemoryManager

Handles the memory pipeline: ingesting transcripts, extracting notes, running compaction, and generating summaries. Operates across global, session, module, and task scopes.

### CacheManager

Provides response caching with TTL-based expiration. Maintains an index for fast lookups and supports pruning of expired entries.

### ModelRouter

Routes requests to the appropriate provider and model based on role assignments. Handles fallback chains, timeouts, and retries.

### ProviderManager

Manages provider instances, health checks, and model discovery. Loads provider configurations and instantiates the correct adapter classes.

### ToolRegistry

Discovers, registers, and manages tools. Provides tool listing, lookup by name, and execution dispatch. Tools are validated against the ToolInterface contract.

### ServiceLoop

The persistent process that drives background operations. Runs in a loop: check task queues, process pending tasks, run heartbeats, perform maintenance, sleep, and repeat.

## Data Flow

### Interactive Chat

```
User Input
    │
    ▼
Chat REPL (parses slash commands or passes message)
    │
    ▼
ModelRouter (resolves role -> provider + model)
    │
    ▼
Provider Adapter (sends request to model API)
    │
    ▼
Model Response
    │
    ▼
Transcript (appended to session transcript.ndjson)
    │
    ▼
Memory Pipeline (notes extracted, compaction scheduled)
    │
    ▼
Response displayed to user
```

### Background Task Processing

```
Task Queued (task.json created with status: queued)
    │
    ▼
ServiceLoop picks up task
    │
    ▼
Task status -> running
    │
    ▼
Steps executed (steps.ndjson appended)
    │
    ▼
Progress reported (progress.ndjson appended)
    │
    ▼
Task status -> completed/failed
    │
    ▼
Output written (output.md, artifacts/)
```

## Service Loop

The service loop is the heartbeat of PHPClaw when running as a persistent service:

```
load configuration
    │
    ▼
┌──► check task queues
│       │
│       ▼
│   process pending tasks
│       │
│       ▼
│   run maintenance (if due)
│       │
│       ▼
│   send heartbeat
│       │
│       ▼
│   health check providers
│       │
│       ▼
│   sleep (configurable interval)
│       │
└───────┘
```

The loop continues until a shutdown signal is received (SIGTERM, SIGINT).

## Design Principles

### File-Based Everything

All state is stored in files. JSON for structured data, NDJSON for append-only logs, Markdown for human-readable summaries. No database, no external state stores. This makes the system inspectable, portable, and simple to back up.

### Template-Driven Extensibility

New tools and providers are created from templates via scaffold commands. This ensures consistent structure and reduces boilerplate errors.

### Explicit Control Flow

No magic, no hidden dependency injection containers, no event buses. The code path from command to storage is traceable by reading the source. Libraries are instantiated and called directly.

### Append-Only Logs

Transcripts, task steps, progress entries, and memory notes are append-only NDJSON files. Data is never modified or deleted from these logs. This provides a complete audit trail.

### Derived Summaries

Summaries and compacted artifacts are always derived from raw data. They can be regenerated at any time. The raw logs are the source of truth; summaries are convenience artifacts.
