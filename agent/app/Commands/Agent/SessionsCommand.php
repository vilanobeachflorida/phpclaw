<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Session\SessionManager;

class SessionsCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:sessions';
    protected $description = 'List chat sessions';

    public function run(array $params)
    {
        $sessions = new SessionManager(new FileStorage());
        $list = $sessions->list();

        CLI::write('=== Sessions ===', 'green');
        CLI::newLine();

        if (empty($list)) {
            CLI::write('  No sessions yet.', 'light_gray');
            return;
        }

        $activeId = $sessions->getActiveId();
        foreach ($list as $s) {
            $marker = ($s['id'] === $activeId) ? ' *' : '';
            $color = ($s['status'] ?? 'active') === 'active' ? 'white' : 'dark_gray';
            CLI::write("  [{$s['status']}] {$s['id']} - {$s['name']}{$marker}", $color);
            CLI::write("    Created: {$s['created_at']}", 'light_gray');
        }
    }
}
