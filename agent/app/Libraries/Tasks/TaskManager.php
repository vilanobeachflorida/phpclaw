<?php

namespace App\Libraries\Tasks;

use App\Libraries\Storage\FileStorage;

/**
 * Manages background tasks: create, update, track progress, cancel.
 * Each task is stored as a directory with task.json, steps.ndjson, progress.ndjson, etc.
 */
class TaskManager
{
    private FileStorage $storage;

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
    }

    public function create(array $params): array
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));

        $task = [
            'id' => $id,
            'title' => $params['title'] ?? 'Untitled Task',
            'description' => $params['description'] ?? '',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'status' => 'queued',
            'current_step' => 0,
            'total_steps_estimate' => $params['total_steps'] ?? null,
            'originating_session_id' => $params['session_id'] ?? null,
            'assigned_module' => $params['module'] ?? null,
            'assigned_role' => $params['role'] ?? null,
            'provider' => $params['provider'] ?? null,
            'model' => $params['model'] ?? null,
            'priority' => $params['priority'] ?? 'normal',
            'retries' => 0,
            'last_error' => null,
            'artifact_paths' => [],
            'checkpoint_paths' => [],
            'metadata' => $params['metadata'] ?? [],
        ];

        $this->storage->writeJson("tasks/{$id}/task.json", $task);

        // Create artifact and checkpoint dirs
        $this->storage->ensureDir($this->storage->path('tasks', $id, 'artifacts'));
        $this->storage->ensureDir($this->storage->path('tasks', $id, 'checkpoints'));

        // Update index
        $index = $this->storage->readJson('tasks/index.json') ?? ['tasks' => []];
        $index['tasks'][] = ['id' => $id, 'title' => $task['title'], 'status' => 'queued', 'created_at' => $task['created_at']];
        $this->storage->writeJson('tasks/index.json', $index);

        // Update active tasks state
        $active = $this->storage->readJson('state/active-tasks.json') ?? ['tasks' => []];
        $active['tasks'][] = $id;
        $this->storage->writeJson('state/active-tasks.json', $active);

        return $task;
    }

    public function get(string $id): ?array
    {
        return $this->storage->readJson("tasks/{$id}/task.json");
    }

    public function updateStatus(string $id, string $status, string $error = null): bool
    {
        $task = $this->get($id);
        if (!$task) return false;

        $task['status'] = $status;
        $task['updated_at'] = date('c');
        if ($error) $task['last_error'] = $error;

        $this->storage->writeJson("tasks/{$id}/task.json", $task);

        // Update index
        $index = $this->storage->readJson('tasks/index.json') ?? ['tasks' => []];
        foreach ($index['tasks'] as &$t) {
            if ($t['id'] === $id) {
                $t['status'] = $status;
                break;
            }
        }
        $this->storage->writeJson('tasks/index.json', $index);

        // Remove from active if terminal
        if (in_array($status, ['completed', 'failed', 'cancelled'])) {
            $active = $this->storage->readJson('state/active-tasks.json') ?? ['tasks' => []];
            $active['tasks'] = array_values(array_filter($active['tasks'], fn($t) => $t !== $id));
            $this->storage->writeJson('state/active-tasks.json', $active);
        }

        return true;
    }

    public function addStep(string $id, array $step): bool
    {
        $step['timestamp'] = $step['timestamp'] ?? date('c');
        $step['step_id'] = $step['step_id'] ?? bin2hex(random_bytes(4));

        $result = $this->storage->appendNdjson("tasks/{$id}/steps.ndjson", $step);

        // Update current step count
        $task = $this->get($id);
        if ($task) {
            $task['current_step'] = ($task['current_step'] ?? 0) + 1;
            $task['updated_at'] = date('c');
            $this->storage->writeJson("tasks/{$id}/task.json", $task);
        }

        return $result;
    }

    public function addProgress(string $id, array $progress): bool
    {
        $progress['timestamp'] = $progress['timestamp'] ?? date('c');
        return $this->storage->appendNdjson("tasks/{$id}/progress.ndjson", $progress);
    }

    public function addMessage(string $id, array $message): bool
    {
        $message['timestamp'] = $message['timestamp'] ?? date('c');
        return $this->storage->appendNdjson("tasks/{$id}/messages.ndjson", $message);
    }

    public function getSteps(string $id): array
    {
        return $this->storage->readNdjson("tasks/{$id}/steps.ndjson");
    }

    public function getProgress(string $id): array
    {
        return $this->storage->readNdjson("tasks/{$id}/progress.ndjson");
    }

    public function cancel(string $id): bool
    {
        return $this->updateStatus($id, 'cancelled');
    }

    public function list(string $status = null): array
    {
        $index = $this->storage->readJson('tasks/index.json') ?? ['tasks' => []];
        $tasks = $index['tasks'] ?? [];

        if ($status) {
            $tasks = array_filter($tasks, fn($t) => ($t['status'] ?? '') === $status);
        }

        return array_values($tasks);
    }

    public function getActiveTasks(): array
    {
        $active = $this->storage->readJson('state/active-tasks.json') ?? ['tasks' => []];
        $tasks = [];
        foreach ($active['tasks'] as $id) {
            $task = $this->get($id);
            if ($task) $tasks[] = $task;
        }
        return $tasks;
    }

    public function writeOutput(string $id, string $content): bool
    {
        return $this->storage->writeText("tasks/{$id}/output.md", $content);
    }
}
