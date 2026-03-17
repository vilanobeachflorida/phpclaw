<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Tasks\TaskManager;

class TaskShowCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:task:show';
    protected $description = 'Show task details';
    protected $usage = 'agent:task:show <task_id>';

    public function run(array $params)
    {
        $id = $params[0] ?? null;
        if (!$id) {
            CLI::error('Usage: php spark agent:task:show <task_id>');
            return;
        }

        $tasks = new TaskManager(new FileStorage());
        $task = $tasks->get($id);

        if (!$task) {
            CLI::error("Task not found: {$id}");
            return;
        }

        CLI::write('=== Task: ' . $task['title'] . ' ===', 'green');
        CLI::write(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        CLI::newLine();
        $steps = $tasks->getSteps($id);
        CLI::write("Steps: " . count($steps), 'cyan');
        foreach ($steps as $step) {
            CLI::write("  [{$step['timestamp']}] " . ($step['description'] ?? $step['action'] ?? 'step'));
        }
    }
}
