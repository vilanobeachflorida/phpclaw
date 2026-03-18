<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\UI\TerminalUI;

class RolesCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:roles';
    protected $description = 'List configured model roles';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $config = new ConfigLoader();
        $roles = $config->get('roles', 'roles', []);

        $ui->header('Model Roles');

        $rows = [];
        foreach ($roles as $name => $cfg) {
            $fallback = !empty($cfg['fallback']) ? implode(', ', $cfg['fallback']) : $ui->style('none', 'gray');
            $rows[] = [
                $ui->style($name, 'bright_cyan'),
                $cfg['provider'] ?? '',
                $cfg['model'] ?? '',
                "{$cfg['timeout']}s",
                $fallback,
                $cfg['description'] ?? '',
            ];
        }

        $ui->newLine();
        $ui->table(['Role', 'Provider', 'Model', 'Timeout', 'Fallback', 'Description'], $rows, 'blue');
        $ui->newLine();
    }
}
