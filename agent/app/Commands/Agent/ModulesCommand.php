<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\ConfigLoader;

class ModulesCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:modules';
    protected $description = 'List configured modules';

    public function run(array $params)
    {
        $config = new ConfigLoader();
        $modules = $config->get('modules', 'modules', []);

        CLI::write('=== Modules ===', 'green');
        CLI::newLine();

        foreach ($modules as $name => $cfg) {
            $status = ($cfg['enabled'] ?? false) ? 'ON' : 'OFF';
            $color = ($cfg['enabled'] ?? false) ? 'green' : 'dark_gray';
            CLI::write("  [{$status}] {$name}: {$cfg['description']}", $color);
            CLI::write("    Role: " . ($cfg['role'] ?? 'default'));
            if (!empty($cfg['tools'])) {
                CLI::write("    Tools: " . implode(', ', $cfg['tools']));
            }
        }
    }
}
