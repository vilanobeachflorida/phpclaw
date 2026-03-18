<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Session\SessionManager;
use App\Libraries\UI\TerminalUI;

class SessionsCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:sessions';
    protected $description = 'List chat sessions';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $sessions = new SessionManager(new FileStorage());
        $list = $sessions->list();

        $ui->header('Sessions');

        if (empty($list)) {
            $ui->newLine();
            $ui->dim('No sessions yet. Start one with: php spark agent:chat');
            $ui->newLine();
            return;
        }

        $activeId = $sessions->getActiveId();
        $rows = [];

        foreach ($list as $s) {
            $isActive = $s['id'] === $activeId;
            $statusColor = match($s['status'] ?? 'active') {
                'active'   => 'bright_green',
                'archived' => 'gray',
                default    => 'white',
            };

            $name = $s['name'];
            if ($isActive) {
                $name .= $ui->style(' *', 'bright_yellow');
            }

            $rows[] = [
                $ui->style($s['status'] ?? 'active', $statusColor),
                $name,
                $s['id'],
                $s['created_at'] ?? '',
            ];
        }

        $ui->newLine();
        $ui->table(['Status', 'Name', 'ID', 'Created'], $rows, 'blue');
        $ui->newLine();
    }
}
