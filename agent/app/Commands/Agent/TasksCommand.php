<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Tasks\TaskManager;
use App\Libraries\UI\TerminalUI;

class TasksCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:tasks';
    protected $description = 'List all tasks';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $tasks = new TaskManager(new FileStorage());
        $list = $tasks->list();

        $ui->header('Tasks');

        if (empty($list)) {
            $ui->newLine();
            $ui->dim('No tasks');
            $ui->newLine();
            return;
        }

        $rows = [];
        foreach ($list as $t) {
            $statusColor = match ($t['status'] ?? '') {
                'completed' => 'bright_green',
                'running'   => 'bright_yellow',
                'failed'    => 'bright_red',
                'cancelled' => 'gray',
                'pending'   => 'white',
                default     => 'white',
            };

            $rows[] = [
                $ui->style($t['status'] ?? '?', $statusColor),
                $t['id'] ?? '',
                $t['title'] ?? '',
            ];
        }

        $ui->newLine();
        $ui->table(['Status', 'ID', 'Title'], $rows, 'blue');
        $ui->newLine();
    }
}
