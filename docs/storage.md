# Storage

All runtime data is stored under `writable/agent/`. No database is used. The filesystem is the single source of truth.

## Directory Tree

```
writable/agent/
├── config/                  # Configuration files
│   ├── providers.json       # Provider definitions and credentials
│   ├── roles.json           # Role-to-model mapping
│   ├── modules.json         # Module definitions
│   └── service.json         # Service loop configuration
├── sessions/                # Chat sessions
│   ├── index.json           # Session manifest (id, name, timestamps)
│   └── <session-id>/        # One directory per session
│       ├── session.json     # Session metadata
│       └── transcript.ndjson # Append-only conversation log
├── tasks/                   # Background tasks
│   ├── index.json           # Task manifest
│   └── <task-id>/           # One directory per task
│       ├── task.json        # Task metadata and status
│       ├── steps.ndjson     # Execution steps log
│       ├── progress.ndjson  # Progress updates log
│       ├── messages.ndjson  # Model messages log
│       ├── output.md        # Final output (Markdown)
│       ├── artifacts/       # Task-produced files
│       └── checkpoints/     # Resumable state snapshots
├── memory/                  # Memory system
│   ├── global/              # Global memory scope
│   │   ├── notes.ndjson     # Extracted notes
│   │   ├── summary.md       # Human-readable summary
│   │   └── compacted.json   # Compacted artifact
│   ├── sessions/            # Per-session memory
│   ├── modules/             # Per-module memory
│   └── tasks/               # Per-task memory
├── cache/                   # Response cache
│   ├── index.json           # Cache entry manifest
│   └── entries/             # Cached response files
├── logs/                    # Application logs
│   ├── agent.log            # General agent log
│   └── service.log          # Service loop log
├── prompts/                 # System and module prompts
│   ├── system/              # System-level prompts
│   │   └── default.md       # Default system prompt
│   └── modules/             # Module-specific prompts
│       ├── heartbeat.md
│       ├── reasoning.md
│       ├── coding.md
│       ├── summarizer.md
│       ├── memory.md
│       ├── planner.md
│       ├── browser.md
│       └── tool_router.md
└── templates/               # Scaffold templates
    ├── tool.php.tpl          # Tool scaffold template
    └── provider.php.tpl      # Provider scaffold template
```

## File Formats

### JSON (`.json`)

Used for structured data that is read and written as a whole: configuration, metadata, manifests, compacted artifacts. Files are pretty-printed for human readability.

### NDJSON (`.ndjson`)

Newline-delimited JSON. Used for append-only logs: transcripts, task steps, progress updates, memory notes. Each line is a self-contained JSON object with a timestamp. Files are only appended to, never rewritten.

Example NDJSON entry:
```json
{"ts":"2025-01-15T10:30:00Z","type":"user","content":"Hello, agent."}
```

### Markdown (`.md`)

Used for human-readable summaries and final outputs. Memory summaries and task outputs are written as Markdown so they can be read directly in any text editor or terminal.

### Plain Text (`.log`)

Used for application logs. Standard log format with timestamps and severity levels.

## Naming Conventions

- **Session IDs** -- `ses_<timestamp>_<random>` (e.g., `ses_20250115_a3f2b1`)
- **Task IDs** -- `task_<timestamp>_<random>` (e.g., `task_20250115_c7d9e4`)
- **Cache keys** -- SHA-256 hash of the request parameters
- **Lock files** -- `.lock` suffix appended to the resource filename (e.g., `session.json.lock`)

## Lock Files

Lock files prevent concurrent writes to the same resource. A lock file contains the PID of the holding process and a timestamp. Stale locks (where the PID no longer exists) are automatically cleaned up.

Lock files are used for:
- Session writes
- Task status transitions
- Memory compaction
- Cache writes

## Index and Manifest Pattern

Collections (sessions, tasks, cache) use an `index.json` manifest file that lists all entries with minimal metadata (ID, status, timestamps). This allows fast listing without scanning individual directories.

The index is updated whenever an entry is created, modified, or removed. If the index becomes corrupted, it can be rebuilt by scanning the directory contents.

## No Database Rule

PHPClaw deliberately avoids any database dependency. All state is in flat files. This provides:

- **Portability** -- copy the directory and everything moves with it.
- **Inspectability** -- use `cat`, `jq`, `less` to examine any state.
- **Simplicity** -- no schema migrations, no connection management, no query language.
- **Reliability** -- no database server to crash or corrupt.

For the scale PHPClaw targets (single user, single machine), file-based storage is both sufficient and preferable.
