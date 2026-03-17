<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Tasks\TaskManager;

class TasksCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:tasks';
    protected $description = 'List all tasks';

    public function run(array $params)
    {
        $tasks = new TaskManager(new FileStorage());
        $list = $tasks->list();

        CLI::write('=== Tasks ===', 'green');
        CLI::newLine();

        if (empty($list)) {
            CLI::write('  No tasks.', 'light_gray');
            return;
        }

        foreach ($list as $t) {
            $color = match ($t['status'] ?? '') {
                'completed' => 'green',
                'running' => 'yellow',
                'failed' => 'red',
                'cancelled' => 'dark_gray',
                default => 'white',
            };
            CLI::write("  [{$t['status']}] {$t['id']}: {$t['title']}", $color);
        }
    }
}
