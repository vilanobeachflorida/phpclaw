<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\ConfigLoader;

class ConfigCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:config';
    protected $description = 'Show agent configuration';

    public function run(array $params)
    {
        $config = new ConfigLoader();
        $name = $params[0] ?? null;

        if ($name) {
            $data = $config->load($name);
            CLI::write("=== Config: {$name} ===", 'green');
            CLI::write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            CLI::write('Available configs: app, roles, modules, providers, tools, service', 'green');
            CLI::write('Usage: php spark agent:config <name>');
        }
    }
}
