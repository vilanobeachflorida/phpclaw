# Memory System

## Overview

PHPClaw implements a layered memory system that preserves conversation context across sessions while managing storage size through compaction. Raw logs are never destroyed -- summaries and compacted artifacts are derived views.

## Layered Memory

The memory system operates in four layers, from raw to refined:

```
Raw Logs (transcript.ndjson)
  │
  ▼
Notes (notes.ndjson)
  │
  ▼
Summaries (summary.md)
  │
  ▼
Compacted Artifacts (compacted/*.json)
```

### Raw Logs

The complete conversation transcript stored as NDJSON. Every user message, assistant response, tool call, and tool result is recorded verbatim. Raw logs are append-only and never modified or deleted.

### Notes

Key facts, decisions, and actionable items extracted from raw logs. Notes are stored as NDJSON with metadata (timestamp, source, scope, tags). Extraction happens automatically after each conversation turn.

### Summaries

Human-readable Markdown summaries generated from notes. Summaries capture the essential context in a compact form suitable for inclusion in prompts. They are regenerated during compaction.

### Compacted Artifacts

Structured JSON artifacts that represent the fully compacted state of a memory scope. Compacted artifacts combine and deduplicate notes, resolve contradictions, and produce a minimal representation of accumulated knowledge.

## Memory Pipeline

### 1. Ingest Transcript

After each conversation turn, the new transcript entries are passed to the memory system. This happens automatically during chat and task execution.

### 2. Extract Notes

The memory module analyzes transcript entries and extracts structured notes:

```json
{
  "timestamp": "2024-01-15T10:30:00Z",
  "scope": "session",
  "source": "session-abc123",
  "type": "fact",
  "content": "User prefers Python for scripting tasks",
  "tags": ["preference", "python"]
}
```

Note types include: `fact`, `decision`, `action`, `preference`, `context`, and `observation`.

### 3. Periodic Compaction

Compaction runs on demand (`agent:memory:compact`) or during scheduled maintenance. The compaction process reads accumulated notes, merges related items, resolves conflicts, and produces a fresh compacted artifact and summary.

## Memory Scopes

### Global

Persists across all sessions. Stores user preferences, frequently used patterns, and long-term context. Located at `writable/agent/memory/global/`.

### Session

Scoped to a single chat session. Stores conversation context, decisions made, and session-specific notes. Located at `writable/agent/memory/sessions/<session-id>/`.

### Module

Scoped to a specific module. Stores module-specific patterns and learned behaviors. Located at `writable/agent/memory/modules/<module-name>/`.

### Task

Scoped to a single task. Stores task-specific context and intermediate results. Located at `writable/agent/memory/tasks/<task-id>/`.

## Compaction Process

When compaction runs for a given scope:

1. **Read notes** -- All notes from `notes.ndjson` are loaded.
2. **Load previous compacted state** -- The most recent compacted artifact (if any) is loaded as a baseline.
3. **Merge and deduplicate** -- New notes are merged with the existing compacted state. Duplicate or superseded entries are removed.
4. **Generate compacted artifact** -- A new JSON artifact is written to `compacted/` with a timestamped filename.
5. **Generate summary** -- A Markdown summary is generated from the compacted artifact and written to `summary.md`.
6. **Update index** -- The memory index is updated to point to the latest compacted artifact.

Raw logs and notes are never destroyed during compaction. Only derived artifacts are regenerated.

## Commands

### `agent:memory:show`

Display memory contents for a given scope.

```bash
# Show global memory summary
php spark agent:memory:show --scope=global

# Show session memory
php spark agent:memory:show --scope=session --id=abc123

# Show raw notes
php spark agent:memory:show --scope=global --format=notes
```

### `agent:memory:compact`

Run compaction on a memory scope.

```bash
# Compact global memory
php spark agent:memory:compact --scope=global

# Compact a specific session
php spark agent:memory:compact --scope=session --id=abc123

# Compact all scopes
php spark agent:memory:compact --all
```
