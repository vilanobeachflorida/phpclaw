# Task System

## Overview

PHPClaw supports background task execution through a queue-based system. Tasks are processed by the service loop and can be monitored, tailed, and cancelled from the CLI.

## Task Lifecycle

```
queued ──► running ──► completed
              │
              ├──► failed
              │
              └──► cancelled
```

### States

- **queued** -- Task has been created and is waiting for the service loop to pick it up.
- **running** -- Task is actively being executed by the service loop.
- **completed** -- Task finished successfully. Output and artifacts are available.
- **failed** -- Task encountered an unrecoverable error. Error details are recorded.
- **cancelled** -- Task was cancelled by the user before completion.

## Task Storage

Each task has its own directory under `writable/agent/tasks/<task-id>/`:

```
<task-id>/
├── task.json           # Task metadata and current state
├── steps.ndjson        # Step-by-step execution log
├── progress.ndjson     # Progress updates (percentage, status messages)
├── messages.ndjson     # AI messages generated during execution
├── output.md           # Final human-readable output
├── artifacts/          # Files produced by the task
└── checkpoints/        # Resumable state snapshots
```

### task.json

Contains task metadata:

```json
{
  "id": "task-abc123",
  "status": "running",
  "type": "coding",
  "description": "Refactor the authentication module",
  "module": "coding",
  "provider": "ollama",
  "model": "llama3",
  "created_at": "2024-01-15T10:00:00Z",
  "started_at": "2024-01-15T10:00:05Z",
  "completed_at": null,
  "error": null
}
```

### steps.ndjson

Each line records a step in task execution:

```json
{"step": 1, "action": "analyze", "input": "...", "output": "...", "timestamp": "..."}
{"step": 2, "action": "tool_call", "tool": "file_read", "args": {...}, "result": {...}, "timestamp": "..."}
```

### progress.ndjson

Progress updates for monitoring:

```json
{"percent": 25, "message": "Analyzing existing code", "timestamp": "..."}
{"percent": 50, "message": "Generating refactored implementation", "timestamp": "..."}
```

### messages.ndjson

All AI messages generated during the task, in transcript format.

### output.md

The final output of the task in Markdown format. This is what gets displayed when viewing a completed task.

### artifacts/

Any files the task produces (generated code, reports, etc.) are stored here.

### checkpoints/

State snapshots that allow a task to be resumed after interruption. Each checkpoint is a JSON file capturing the task state at a point in time.

## Creating Tasks

Tasks are typically created from the chat REPL or programmatically:

```bash
# From chat, tasks may be created by the agent when handling complex requests
# The agent determines when a task should be backgrounded

# Tasks can also be queued from the service loop for scheduled operations
```

## Monitoring Tasks

### List Tasks

```bash
php spark agent:tasks

# Filter by status
php spark agent:tasks --status=running
```

### Show Task Details

```bash
php spark agent:task:show <task-id>
```

Displays the task metadata, current progress, and recent steps.

### Tail Task Output

```bash
php spark agent:task:tail <task-id>
```

Follows the task output in real time, similar to `tail -f`. Shows new steps, progress updates, and messages as they are appended.

### Cancel a Task

```bash
php spark agent:task:cancel <task-id>
```

Sets the task status to `cancelled`. If the task is currently running, the service loop will stop execution at the next check point.

## Service Loop Task Processing

The service loop processes tasks in the following order:

1. **Check queue** -- Read `tasks/index.json` for tasks in `queued` state.
2. **Pick task** -- Select the oldest queued task (FIFO ordering).
3. **Transition to running** -- Update task status and `started_at` timestamp.
4. **Execute steps** -- Run the task through its module, logging steps and progress.
5. **Handle completion** -- On success, write `output.md`, update status to `completed`. On error, record the error and set status to `failed`.
6. **Update index** -- Reflect the new status in `tasks/index.json`.

The service loop processes one task at a time. New tasks remain queued until the current task finishes.
