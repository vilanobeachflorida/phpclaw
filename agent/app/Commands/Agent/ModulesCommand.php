<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\UI\TerminalUI;

class ModulesCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:modules';
    protected $description = 'List configured modules';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $config = new ConfigLoader();
        $modules = $config->get('modules', 'modules', []);

        $ui->header('Modules');

        $rows = [];
        foreach ($modules as $name => $cfg) {
            $status = ($cfg['enabled'] ?? false)
                ? $ui->style('ON', 'bright_green')
                : $ui->style('OFF', 'red');
            $tools = !empty($cfg['tools']) ? implode(', ', $cfg['tools']) : $ui->style('none', 'gray');
            $rows[] = [
                $status,
                $ui->style($name, 'bright_cyan'),
                $cfg['role'] ?? 'default',
                $tools,
                $cfg['description'] ?? '',
            ];
        }

        $ui->newLine();
        $ui->table(['', 'Module', 'Role', 'Tools', 'Description'], $rows, 'blue');
        $ui->newLine();
    }
}
