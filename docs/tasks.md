# Task System

PHPClaw supports background tasks that run asynchronously through the service loop. Tasks are queued, processed, and tracked entirely through the file system.

## Task Lifecycle

```
queued ──► running ──► completed
                  ├──► failed
                  └──► cancelled
```

- **queued** -- task has been created and is waiting for the service loop to pick it up.
- **running** -- task is actively being processed by the service loop.
- **completed** -- task finished successfully and output is available.
- **failed** -- task encountered an error. Error details are recorded in the task metadata.
- **cancelled** -- task was manually cancelled by the user before completion.

## Task Storage

Each task is stored in its own directory under `writable/agent/tasks/<task-id>/`:

```
<task-id>/
├── task.json          # Task metadata, status, timestamps, configuration
├── steps.ndjson       # Append-only log of execution steps
├── progress.ndjson    # Append-only log of progress updates
├── messages.ndjson    # Append-only log of model messages
├── output.md          # Final output in Markdown format
├── artifacts/         # Files produced by the task
└── checkpoints/       # State snapshots for resumability
```

### task.json

Contains the task definition and current state:

```json
{
  "id": "task_20250115_c7d9e4",
  "name": "Analyze codebase",
  "status": "running",
  "module": "coding",
  "created_at": "2025-01-15T10:00:00Z",
  "started_at": "2025-01-15T10:00:05Z",
  "completed_at": null,
  "error": null,
  "params": {}
}
```

### steps.ndjson

Each step of execution is logged as a JSON line:

```json
{"ts":"2025-01-15T10:00:05Z","step":1,"action":"scan_files","status":"completed"}
{"ts":"2025-01-15T10:00:12Z","step":2,"action":"analyze_patterns","status":"running"}
```

### progress.ndjson

Progress updates for monitoring:

```json
{"ts":"2025-01-15T10:00:10Z","percent":25,"message":"Scanned 50 of 200 files"}
{"ts":"2025-01-15T10:00:15Z","percent":50,"message":"Analysis in progress"}
```

### messages.ndjson

Model interactions during task execution, stored in the same format as session transcripts.

### output.md

The final human-readable output of the task, written when the task completes.

### artifacts/

Any files produced by the task (generated code, reports, exports) are stored here.

### checkpoints/

State snapshots that allow task resumption if the service restarts. Each checkpoint is a JSON file with the task's internal state at that point.

## Creating Tasks

Tasks are created through the chat interface or programmatically:

```bash
# From the chat REPL
> /tasks create "Analyze the project structure"

# Programmatically via the TaskManager API
$taskManager->create('Analyze the project structure', 'coding', $params);
```

## Monitoring Progress

### List Tasks

```bash
php spark agent:tasks
```

Displays all tasks with their current status, creation time, and a brief description.

### Show Task Details

```bash
php spark agent:task:show <task-id>
```

Displays full task metadata, recent steps, and progress information.

### Tail Task Output

```bash
php spark agent:task:tail <task-id>
```

Streams live output from a running task, similar to `tail -f`. Displays new steps, progress updates, and messages as they are appended.

### Cancel a Task

```bash
php spark agent:task:cancel <task-id>
```

Cancels a queued or running task. If the task is running, the service loop will stop it at the next checkpoint opportunity. The task status is set to `cancelled`.

## Service Loop Task Processing

The service loop picks up queued tasks in FIFO order:

1. Read `tasks/index.json` for tasks with status `queued`
2. Select the oldest queued task
3. Set status to `running` in `task.json`
4. Execute the task using the configured module
5. Log steps and progress as execution proceeds
6. On completion, write `output.md` and set status to `completed`
7. On error, record the error in `task.json` and set status to `failed`
8. Update `tasks/index.json`

Only one task runs at a time. This keeps the system simple and avoids resource contention.

## CLI Commands

| Command | Description |
|---|---|
| `agent:tasks` | List all tasks with status |
| `agent:task:show <id>` | Show task details |
| `agent:task:tail <id>` | Tail live output of a running task |
| `agent:task:cancel <id>` | Cancel a queued or running task |
