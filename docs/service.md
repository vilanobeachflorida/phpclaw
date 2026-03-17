# Service Loop

## Overview

The service loop is PHPClaw's long-running background process. It handles task execution, periodic maintenance, health monitoring, and heartbeat checks. Start it with `agent:serve` and manage it with `agent:status`.

## ServiceLoop Class

```php
class ServiceLoop
{
    public function start(): void;
    public function stop(): void;
    public function isRunning(): bool;
    public function getStatus(): array;

    protected function tick(): void;
    protected function processTasks(): void;
    protected function runHeartbeat(): void;
    protected function runMaintenance(): void;
    protected function checkHealth(): void;
}
```

## Loop Responsibilities

Each tick of the service loop performs these operations in order:

### 1. Process Tasks

Check the task queue for pending tasks. Pick the oldest queued task and execute it. Only one task runs at a time. See `tasks.md` for the full task lifecycle.

### 2. Heartbeat

Send a lightweight prompt to the configured heartbeat provider/model to verify the system is responsive. The heartbeat module is used for this. Results are logged but not stored in memory.

### 3. Maintenance

Run periodic maintenance routines:

- **Memory compaction** -- Compact memory scopes that have accumulated enough notes.
- **Cache pruning** -- Remove expired cache entries.
- **Log rotation** -- Truncate logs that exceed configured size limits.

Maintenance runs at a configurable interval (default: every 60 ticks).

### 4. Health Checks

Check connectivity to all enabled providers. Record health status in the service state file. Unhealthy providers are flagged and excluded from routing until they recover.

Health checks run at a configurable interval (default: every 10 ticks).

## Running Under systemd

PHPClaw includes a systemd service file (`phpclaw.service`) for running the service loop as a managed daemon.

### Installation

```bash
# Copy service file
sudo cp phpclaw.service /etc/systemd/system/

# Edit paths and user as needed
sudo systemctl daemon-reload

# Start the service
sudo systemctl start phpclaw

# Enable on boot
sudo systemctl enable phpclaw

# Check status
sudo systemctl status phpclaw
```

### Logs

When running under systemd, logs are written to:

- `/var/log/phpclaw/service.log` -- Standard output
- `/var/log/phpclaw/error.log` -- Standard error

Create the log directory before starting:

```bash
sudo mkdir -p /var/log/phpclaw
sudo chown www-data:www-data /var/log/phpclaw
```

## Configuration

The service loop is configured in `writable/agent/config/service.json`:

```json
{
  "tick_interval": 5,
  "heartbeat_interval": 10,
  "maintenance_interval": 60,
  "health_check_interval": 10,
  "max_task_duration": 3600,
  "graceful_shutdown_timeout": 30,
  "pid_file": "writable/agent/service.pid",
  "state_file": "writable/agent/service.state.json"
}
```

| Setting | Default | Description |
|---|---|---|
| `tick_interval` | 5 | Seconds between each loop tick |
| `heartbeat_interval` | 10 | Ticks between heartbeat checks |
| `maintenance_interval` | 60 | Ticks between maintenance runs |
| `health_check_interval` | 10 | Ticks between provider health checks |
| `max_task_duration` | 3600 | Maximum seconds a task can run before forced timeout |
| `graceful_shutdown_timeout` | 30 | Seconds to wait for current task to finish on shutdown |
| `pid_file` | `service.pid` | Path to the PID file |
| `state_file` | `service.state.json` | Path to the state file |

## State Files

### PID File (`service.pid`)

Contains the process ID of the running service loop. Used to detect if the service is already running and for sending signals.

### State File (`service.state.json`)

Contains the current state of the service:

```json
{
  "status": "running",
  "started_at": "2024-01-15T10:00:00Z",
  "last_tick": "2024-01-15T10:05:30Z",
  "tick_count": 66,
  "tasks_processed": 12,
  "current_task": null,
  "provider_health": {
    "ollama": true,
    "chatgpt": true,
    "claude": false
  },
  "last_maintenance": "2024-01-15T10:05:00Z",
  "last_heartbeat": "2024-01-15T10:05:30Z"
}
```

## Graceful Shutdown

The service loop handles POSIX signals for graceful shutdown:

- **SIGTERM** -- Initiates graceful shutdown. The current tick completes, and if a task is running, PHPClaw waits up to `graceful_shutdown_timeout` seconds for it to finish.
- **SIGINT** -- Same as SIGTERM (handles Ctrl+C).
- **SIGHUP** -- Reloads configuration without stopping the service.

Shutdown sequence:

1. Signal received.
2. Service sets `shutting_down` flag.
3. Current tick completes.
4. If a task is running, wait for it to finish (up to timeout).
5. Write final state to state file.
6. Remove PID file.
7. Exit.

## Commands

### `agent:serve`

Start the service loop:

```bash
php spark agent:serve
```

The command runs in the foreground. Use systemd, screen, or tmux for background operation.

Options:

```
--tick-interval=N    Override tick interval (seconds)
--no-heartbeat       Disable heartbeat checks
--no-maintenance     Disable maintenance routines
```

### `agent:status`

Show service status:

```bash
php spark agent:status
```

Displays:

- Whether the service is running
- Uptime and tick count
- Tasks processed
- Provider health status
- Last maintenance and heartbeat times
