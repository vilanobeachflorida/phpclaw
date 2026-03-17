# Service Loop

The PHPClaw service loop is a long-lived process that runs continuously, processing background tasks, performing maintenance, and monitoring system health.

## ServiceLoop Class

Located at `app/Libraries/Service/ServiceLoop.php`.

## Responsibilities

Each iteration of the loop:

1. **Load config and state** - Read current configuration
2. **Process tasks** - Check for queued/running background tasks
3. **Heartbeat** - Periodic health check (configurable interval)
4. **Maintenance** - Memory compaction, cache pruning (configurable interval)
5. **Provider health** - Check provider availability (configurable interval)
6. **Update state** - Write loop.json, heartbeat.json
7. **Sleep** - Brief pause before next iteration

## Configuration

In `writable/agent/config/service.json`:

```json
{
  "service": {
    "enabled": true,
    "loop_interval_ms": 1000,
    "heartbeat_interval": 60,
    "maintenance_interval": 3600,
    "provider_health_interval": 300,
    "max_concurrent_tasks": 3
  }
}
```

## State Files

- `state/service.json` - Service status, PID, start time
- `state/heartbeat.json` - Last heartbeat timestamp
- `state/loop.json` - Current iteration, last tick
- `state/active-tasks.json` - List of active task IDs
- `state/provider-health.json` - Provider health status

## Running the Service

```bash
# Foreground
php spark agent:serve

# Check status
php spark agent:status
```

## Running Under systemd

Use the provided `phpclaw.service` file:

```bash
sudo cp phpclaw.service /etc/systemd/system/
sudo systemctl enable phpclaw
sudo systemctl start phpclaw
```

## Graceful Shutdown

The service handles SIGTERM and SIGINT for graceful shutdown. It completes the current iteration before stopping.

## Logs

Service activity is logged to `writable/agent/logs/service.log`.
Maintenance results are logged to `writable/agent/logs/maintenance.ndjson`.
