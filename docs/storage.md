# Storage

## Overview

All PHPClaw runtime data is stored under `writable/agent/`. No database is used. Every piece of state -- configuration, sessions, tasks, memory, cache -- is a file on disk.

## Directory Tree

```
writable/agent/
├── config/                     # Configuration files
│   ├── providers.json          # Provider definitions and credentials
│   ├── roles.json              # Role-to-model mappings
│   ├── modules.json            # Module definitions
│   └── service.json            # Service loop configuration
│
├── sessions/                   # Chat sessions
│   ├── index.json              # Session index (ID, created, last active)
│   └── <session-id>/           # Individual session directory
│       ├── session.json        # Session metadata and state
│       ├── transcript.ndjson   # Append-only conversation log
│       └── memory/             # Session-scoped memory
│           ├── notes.ndjson    # Extracted notes
│           ├── summary.md      # Current summary
│           └── compacted/      # Compaction artifacts
│
├── tasks/                      # Background tasks
│   ├── index.json              # Task index (ID, status, created)
│   └── <task-id>/              # Individual task directory
│       ├── task.json           # Task metadata and state
│       ├── steps.ndjson        # Step execution log
│       ├── progress.ndjson     # Progress updates
│       ├── messages.ndjson     # Messages generated during task
│       ├── output.md           # Final task output
│       ├── artifacts/          # Files produced by the task
│       └── checkpoints/        # Resumable state snapshots
│
├── memory/                     # Memory system
│   ├── global/                 # Global memory scope
│   │   ├── notes.ndjson        # Global notes
│   │   ├── summary.md          # Global summary
│   │   └── compacted/          # Compacted artifacts
│   ├── sessions/               # Per-session memory
│   ├── modules/                # Per-module memory
│   └── tasks/                  # Per-task memory
│
├── cache/                      # Response and artifact cache
│   ├── index.json              # Cache index with TTL metadata
│   └── entries/                # Cached data files
│
├── logs/                       # Runtime logs
│   ├── service.log             # Service loop log
│   └── errors.log              # Error log
│
├── prompts/                    # System and module prompts
│   ├── system/                 # System-level prompts
│   │   └── default.md          # Default system prompt
│   └── modules/                # Per-module prompts
│       ├── heartbeat.md
│       ├── reasoning.md
│       ├── coding.md
│       ├── summarizer.md
│       ├── memory.md
│       ├── planner.md
│       ├── browser.md
│       └── tool_router.md
│
└── templates/                  # Scaffolding templates
    ├── tool.php.tpl            # Tool class template
    └── provider.php.tpl        # Provider class template
```

## File Formats

### JSON (`.json`)

Used for structured data that is read and written as a whole: configuration files, metadata, indexes, and state snapshots. Files are pretty-printed for readability.

### NDJSON (`.ndjson`)

Newline-delimited JSON. Used for append-only logs: transcripts, step logs, progress updates, and notes. Each line is a self-contained JSON object. This format supports efficient appending without reading the entire file.

### Markdown (`.md`)

Used for human-readable summaries and output: memory summaries, task output, and prompts. These files are derived from structured data and can be regenerated.

### Plain Text (`.log`)

Used for runtime logs: service log and error log. Standard line-oriented log format with timestamps.

## Naming Conventions

- **IDs** are generated as lowercase UUID-v4 strings (e.g., `a3f8b2c1-4d5e-6f7a-8b9c-0d1e2f3a4b5c`).
- **Directories** for sessions and tasks are named by their ID.
- **Timestamps** in log entries use ISO 8601 format (`2024-01-15T10:30:00Z`).
- **Config files** use descriptive names matching their purpose.

## Lock Files

Write operations that modify shared state use `.lock` files to prevent concurrent corruption. A lock file is created before writing and removed after the write completes. The locking mechanism uses PHP's `flock()` for advisory locking.

Lock files are named by appending `.lock` to the target file path:

```
sessions/index.json      -> sessions/index.json.lock
tasks/index.json         -> tasks/index.json.lock
```

## Index and Manifest Pattern

Collection directories (sessions, tasks, cache) maintain an `index.json` file that serves as a manifest. The index contains a lightweight summary of each item (ID, status, timestamps) to avoid scanning individual directories for listing operations.

The index is updated whenever an item is created, modified, or removed. If the index becomes inconsistent, it can be rebuilt by scanning the directory contents.

## No Database Rule

PHPClaw deliberately avoids database dependencies. The file-based approach provides:

- **Portability** -- copy the `writable/agent/` directory to move all state.
- **Inspectability** -- read any file directly with standard tools.
- **Simplicity** -- no connection strings, migrations, or schema management.
- **Reliability** -- no database server to crash or corrupt.

For the workload PHPClaw handles (single-user, moderate throughput), file-based storage is sufficient and preferable to the complexity of a database.
