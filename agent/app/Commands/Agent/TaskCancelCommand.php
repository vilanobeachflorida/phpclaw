<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Tasks\TaskManager;

class TaskCancelCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:task:cancel';
    protected $description = 'Cancel a running task';
    protected $usage = 'agent:task:cancel <task_id>';

    public function run(array $params)
    {
        $id = $params[0] ?? null;
        if (!$id) {
            CLI::error('Usage: php spark agent:task:cancel <task_id>');
            return;
        }

        $tasks = new TaskManager(new FileStorage());
        $task = $tasks->get($id);

        if (!$task) {
            CLI::error("Task not found: {$id}");
            return;
        }

        $tasks->cancel($id);
        CLI::write("Task cancelled: {$id}", 'green');
    }
}
