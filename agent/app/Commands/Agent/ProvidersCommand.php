<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Service\ProviderManager;
use App\Libraries\UI\TerminalUI;

class ProvidersCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:providers';
    protected $description = 'List configured providers and health status';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $config = new ConfigLoader();
        $manager = new ProviderManager($config);
        $manager->loadAll();

        $ui->header('Providers');

        $providersConfig = $config->get('providers', 'providers', []);
        $rows = [];

        foreach ($providersConfig as $name => $cfg) {
            $enabled = $cfg['enabled'] ?? false;
            $status = $enabled
                ? $ui->style('enabled', 'bright_green')
                : $ui->style('disabled', 'gray');

            $health = '';
            if ($enabled) {
                $provider = $manager->get($name);
                if ($provider) {
                    try {
                        $check = $provider->healthCheck();
                        $healthStatus = $check['status'] ?? 'unknown';
                        $health = match($healthStatus) {
                            'ok'    => $ui->style('healthy', 'bright_green'),
                            default => $ui->style($healthStatus, 'red'),
                        };
                    } catch (\Throwable $e) {
                        $health = $ui->style('error', 'red');
                    }
                }
            }

            $rows[] = [
                $ui->style($name, 'bright_cyan'),
                $status,
                $cfg['description'] ?? $cfg['type'] ?? '',
                $health,
            ];
        }

        $ui->newLine();
        $ui->table(['Provider', 'Status', 'Description', 'Health'], $rows, 'blue');
        $ui->newLine();
    }
}
