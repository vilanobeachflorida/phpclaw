<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\ConfigLoader;

class RolesCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:roles';
    protected $description = 'List configured model roles';

    public function run(array $params)
    {
        $config = new ConfigLoader();
        $roles = $config->get('roles', 'roles', []);

        CLI::write('=== Model Roles ===', 'green');
        CLI::newLine();

        foreach ($roles as $name => $cfg) {
            CLI::write("  {$name}:", 'cyan');
            CLI::write("    Provider: {$cfg['provider']} / Model: {$cfg['model']}");
            CLI::write("    Timeout: {$cfg['timeout']}s / Retry: {$cfg['retry']}");
            if (!empty($cfg['fallback'])) {
                CLI::write("    Fallback: " . implode(', ', $cfg['fallback']));
            }
            CLI::write("    {$cfg['description']}", 'light_gray');
        }
    }
}
