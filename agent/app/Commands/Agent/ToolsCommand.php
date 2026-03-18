<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Service\ToolRegistry;
use App\Libraries\UI\TerminalUI;

class ToolsCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:tools';
    protected $description = 'List available tools';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $config = new ConfigLoader();
        $registry = new ToolRegistry($config);
        $registry->loadAll();

        $ui->header('Tools');

        $rows = [];
        foreach ($registry->listAll() as $tool) {
            $status = $tool['enabled']
                ? $ui->style('ON', 'bright_green')
                : $ui->style('OFF', 'red');
            $rows[] = [$status, $tool['name'], $tool['description'] ?? ''];
        }

        $ui->newLine();
        $ui->table(['', 'Tool', 'Description'], $rows, 'blue');
        $ui->newLine();
    }
}
