<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;

class StatusCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:status';
    protected $description = 'Show agent system status';

    public function run(array $params)
    {
        $storage = new FileStorage();

        CLI::write('=== PHPClaw Agent Status ===', 'green');
        CLI::newLine();

        // Service state
        $state = $storage->readJson('state/service.json') ?? [];
        CLI::write('Service: ' . ($state['status'] ?? 'unknown'), 'cyan');
        if (isset($state['pid'])) CLI::write('PID: ' . $state['pid']);
        if (isset($state['started_at'])) CLI::write('Started: ' . $state['started_at']);

        // Heartbeat
        $heartbeat = $storage->readJson('state/heartbeat.json') ?? [];
        CLI::write('Last heartbeat: ' . ($heartbeat['last_check'] ?? 'never'));

        // Loop state
        $loop = $storage->readJson('state/loop.json') ?? [];
        CLI::write('Loop iteration: ' . ($loop['iteration'] ?? 0));

        // Active tasks
        $active = $storage->readJson('state/active-tasks.json') ?? ['tasks' => []];
        CLI::write('Active tasks: ' . count($active['tasks']));

        // Sessions
        $sessions = $storage->readJson('sessions/index.json') ?? ['sessions' => []];
        CLI::write('Sessions: ' . count($sessions['sessions']));
        CLI::write('Active session: ' . ($sessions['active_session'] ?? 'none'));
    }
}
