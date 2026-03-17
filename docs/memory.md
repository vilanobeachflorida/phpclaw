# Memory System

PHPClaw uses a layered memory system that transforms raw conversation data into compact, searchable knowledge.

## Layered Memory

Memory flows through four layers:

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
Compacted Artifacts (compacted.json)
```

1. **Raw Logs** -- complete, unmodified conversation transcripts stored as NDJSON. These are the source of truth and are never destroyed or modified.
2. **Notes** -- key facts, decisions, and action items extracted from raw logs. Stored as NDJSON with timestamps and tags.
3. **Summaries** -- human-readable Markdown documents that distill notes into concise overviews. Regenerated during compaction.
4. **Compacted Artifacts** -- structured JSON containing the most important information in a compact format suitable for inclusion in prompts.

## Memory Pipeline

### Ingest Transcript

After each conversation turn, the transcript entry is appended to the session's `transcript.ndjson`. This happens automatically and requires no user action.

### Extract Notes

Notes extraction reads recent transcript entries and identifies:
- Key facts and statements
- Decisions made
- Action items and tasks
- Code references and file paths
- Error conditions and resolutions

Extracted notes are appended to `notes.ndjson` in the appropriate memory scope.

### Periodic Compaction

Compaction runs on a schedule (or manually) and processes accumulated notes into compact artifacts:

1. Read all notes from `notes.ndjson`
2. Send notes to the memory module for analysis and compression
3. Write `compacted.json` with structured, deduplicated information
4. Generate `summary.md` with a human-readable overview
5. Update the memory index

## Memory Scopes

### Global

Stored in `writable/agent/memory/global/`. Contains knowledge that spans all sessions and tasks. Information promoted to global scope persists indefinitely.

### Session

Stored in `writable/agent/memory/sessions/<session-id>/`. Contains knowledge specific to a single conversation session. Useful for maintaining context across a long conversation.

### Module

Stored in `writable/agent/memory/modules/<module-name>/`. Contains knowledge accumulated by a specific module across its invocations. For example, the coding module might accumulate project-specific patterns.

### Task

Stored in `writable/agent/memory/tasks/<task-id>/`. Contains knowledge relevant to a specific background task. Useful for long-running tasks that need to track state across steps.

## Compaction Process

Compaction is the process of transforming raw notes into dense, useful artifacts:

```
notes.ndjson (may contain hundreds of entries)
    │
    ▼
Memory module analyzes and deduplicates
    │
    ▼
compacted.json (structured, compressed knowledge)
    │
    ▼
summary.md (human-readable overview)
    │
    ▼
Index updated with compaction metadata
```

During compaction:
- Duplicate information is merged
- Outdated information is flagged
- Key facts are prioritized
- The compacted artifact is sized to fit within prompt context limits

## Raw Logs Are Never Destroyed

Raw transcript logs (`transcript.ndjson`) and note logs (`notes.ndjson`) are append-only and permanent. Compaction creates new derived artifacts but never modifies or deletes source data. This ensures:

- Complete audit trail
- Ability to recompact with different strategies
- Debugging and analysis of historical conversations

## Commands

### `agent:memory:show`

Display memory contents for a specified scope:

```bash
# Show global memory summary
php spark agent:memory:show global

# Show session memory
php spark agent:memory:show session <session-id>

# Show module memory
php spark agent:memory:show module coding
```

### `agent:memory:compact`

Manually trigger compaction for a scope:

```bash
# Compact global memory
php spark agent:memory:compact global

# Compact a specific session's memory
php spark agent:memory:compact session <session-id>

# Compact all scopes
php spark agent:memory:compact --all
```
