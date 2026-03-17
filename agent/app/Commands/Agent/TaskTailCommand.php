<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Tasks\TaskManager;

class TaskTailCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:task:tail';
    protected $description = 'Tail task progress';
    protected $usage = 'agent:task:tail <task_id>';

    public function run(array $params)
    {
        $id = $params[0] ?? null;
        if (!$id) {
            CLI::error('Usage: php spark agent:task:tail <task_id>');
            return;
        }

        $tasks = new TaskManager(new FileStorage());
        $task = $tasks->get($id);

        if (!$task) {
            CLI::error("Task not found: {$id}");
            return;
        }

        CLI::write("Tailing task: {$task['title']} [{$task['status']}]", 'green');
        CLI::write('Press Ctrl+C to stop.', 'light_gray');
        CLI::newLine();

        $lastCount = 0;
        while (true) {
            $progress = $tasks->getProgress($id);
            $newEntries = array_slice($progress, $lastCount);
            foreach ($newEntries as $entry) {
                CLI::write("[{$entry['timestamp']}] " . ($entry['message'] ?? json_encode($entry)));
            }
            $lastCount = count($progress);

            // Check if task ended
            $task = $tasks->get($id);
            if (in_array($task['status'] ?? '', ['completed', 'failed', 'cancelled'])) {
                CLI::write("Task ended: {$task['status']}", $task['status'] === 'completed' ? 'green' : 'red');
                break;
            }

            sleep(2);
        }
    }
}
