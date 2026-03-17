<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Service\ToolRegistry;

class ToolsCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:tools';
    protected $description = 'List available tools';

    public function run(array $params)
    {
        $config = new ConfigLoader();
        $registry = new ToolRegistry($config);
        $registry->loadAll();

        CLI::write('=== Tools ===', 'green');
        CLI::newLine();

        foreach ($registry->listAll() as $tool) {
            $status = $tool['enabled'] ? 'ON' : 'OFF';
            $color = $tool['enabled'] ? 'white' : 'dark_gray';
            CLI::write("  [{$status}] {$tool['name']}: {$tool['description']}", $color);
        }
    }
}
