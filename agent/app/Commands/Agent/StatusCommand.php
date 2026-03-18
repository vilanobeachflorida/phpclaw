<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\UI\TerminalUI;

class StatusCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:status';
    protected $description = 'Show agent system status';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $storage = new FileStorage();

        $ui->header('PHPClaw Agent Status');

        // Service state
        $state = $storage->readJson('state/service.json') ?? [];
        $serviceStatus = $state['status'] ?? 'unknown';
        $statusColor = match($serviceStatus) {
            'running' => 'bright_green',
            'stopped' => 'yellow',
            default   => 'gray',
        };

        // Heartbeat
        $heartbeat = $storage->readJson('state/heartbeat.json') ?? [];

        // Loop state
        $loop = $storage->readJson('state/loop.json') ?? [];

        // Active tasks
        $active = $storage->readJson('state/active-tasks.json') ?? ['tasks' => []];

        // Sessions
        $sessions = $storage->readJson('sessions/index.json') ?? ['sessions' => []];

        $ui->newLine();
        $ui->keyValue([
            'Service'        => $ui->style($serviceStatus, $statusColor),
            'PID'            => $state['pid'] ?? $ui->style('none', 'gray'),
            'Started'        => $state['started_at'] ?? $ui->style('never', 'gray'),
            'Last heartbeat' => $heartbeat['last_check'] ?? $ui->style('never', 'gray'),
            'Loop iteration' => $loop['iteration'] ?? 0,
            'Active tasks'   => count($active['tasks']),
            'Sessions'       => count($sessions['sessions']),
            'Active session'  => $sessions['active_session'] ?? $ui->style('none', 'gray'),
        ]);
        $ui->newLine();
    }
}
