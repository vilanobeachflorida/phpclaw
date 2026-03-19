<?php

namespace App\Libraries\Tools;

/**
 * Scheduled task management for the agent.
 * Creates, lists, and removes recurring agent tasks stored as JSON schedules.
 */
class CronScheduleTool extends BaseTool
{
    protected string $name = 'cron_schedule';
    protected string $description = 'Create, list, and remove scheduled recurring agent tasks';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 10,
            'schedule_dir' => 'writable/agent/schedules',
            'max_schedules' => 50,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Action: create, list, delete, show',
            ],
            'id' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Schedule ID (for delete/show actions)',
            ],
            'name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Human-readable name for the schedule (for create)',
            ],
            'interval' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Interval: "5m", "1h", "6h", "1d", or cron expression "*/5 * * * *"',
            ],
            'command' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Agent prompt or shell command to execute on schedule',
            ],
            'type' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Execution type: "prompt" (send to agent) or "shell" (execute command). Default: prompt',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        $action = $args['action'];
        $scheduleDir = $this->config['schedule_dir'] ?? 'writable/agent/schedules';

        if (!is_dir($scheduleDir)) {
            mkdir($scheduleDir, 0755, true);
        }

        return match ($action) {
            'create' => $this->createSchedule($args, $scheduleDir),
            'list'   => $this->listSchedules($scheduleDir),
            'delete' => $this->deleteSchedule($args, $scheduleDir),
            'show'   => $this->showSchedule($args, $scheduleDir),
            default  => $this->error("Unknown action: {$action}. Use: create, list, delete, show"),
        };
    }

    private function createSchedule(array $args, string $dir): array
    {
        if ($err = $this->requireArgs($args, ['interval', 'command'])) return $err;

        $maxSchedules = (int)($this->config['max_schedules'] ?? 50);
        $existing = glob("{$dir}/*.json");
        if (count($existing) >= $maxSchedules) {
            return $this->error("Maximum schedules reached ({$maxSchedules}). Delete some first.");
        }

        $interval = $args['interval'];
        $seconds = $this->parseInterval($interval);
        if ($seconds === null && !$this->isValidCron($interval)) {
            return $this->error("Invalid interval: {$interval}. Use formats like '5m', '1h', '1d' or a cron expression.");
        }

        $id = 'sched_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $schedule = [
            'id' => $id,
            'name' => $args['name'] ?? $args['command'],
            'interval' => $interval,
            'interval_seconds' => $seconds,
            'command' => $args['command'],
            'type' => $args['type'] ?? 'prompt',
            'enabled' => true,
            'created_at' => date('c'),
            'last_run' => null,
            'next_run' => date('c', time() + ($seconds ?? 300)),
            'run_count' => 0,
        ];

        file_put_contents("{$dir}/{$id}.json", json_encode($schedule, JSON_PRETTY_PRINT));

        return $this->success($schedule, "Schedule created: {$id}");
    }

    private function listSchedules(string $dir): array
    {
        $files = glob("{$dir}/*.json");
        $schedules = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $schedules[] = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'interval' => $data['interval'],
                    'type' => $data['type'] ?? 'prompt',
                    'enabled' => $data['enabled'] ?? true,
                    'last_run' => $data['last_run'],
                    'next_run' => $data['next_run'],
                    'run_count' => $data['run_count'] ?? 0,
                ];
            }
        }

        return $this->success([
            'schedules' => $schedules,
            'count' => count($schedules),
        ]);
    }

    private function deleteSchedule(array $args, string $dir): array
    {
        if ($err = $this->requireArgs($args, ['id'])) return $err;

        $id = $args['id'];
        $file = "{$dir}/{$id}.json";

        if (!file_exists($file)) {
            return $this->error("Schedule not found: {$id}");
        }

        unlink($file);
        return $this->success(['id' => $id, 'deleted' => true]);
    }

    private function showSchedule(array $args, string $dir): array
    {
        if ($err = $this->requireArgs($args, ['id'])) return $err;

        $id = $args['id'];
        $file = "{$dir}/{$id}.json";

        if (!file_exists($file)) {
            return $this->error("Schedule not found: {$id}");
        }

        $data = json_decode(file_get_contents($file), true);
        return $this->success($data);
    }

    private function parseInterval(string $interval): ?int
    {
        if (preg_match('/^(\d+)(s|m|h|d)$/', $interval, $m)) {
            $value = (int)$m[1];
            return match ($m[2]) {
                's' => $value,
                'm' => $value * 60,
                'h' => $value * 3600,
                'd' => $value * 86400,
            };
        }
        return null;
    }

    private function isValidCron(string $expr): bool
    {
        // Basic cron validation: 5 space-separated fields
        $parts = preg_split('/\s+/', trim($expr));
        return count($parts) === 5;
    }
}
