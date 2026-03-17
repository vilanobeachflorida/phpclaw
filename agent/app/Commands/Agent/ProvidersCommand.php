<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Service\ProviderManager;

class ProvidersCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:providers';
    protected $description = 'List configured providers and health status';

    public function run(array $params)
    {
        $config = new ConfigLoader();
        $manager = new ProviderManager($config);
        $manager->loadAll();

        CLI::write('=== Providers ===', 'green');
        CLI::newLine();

        $providersConfig = $config->get('providers', 'providers', []);
        foreach ($providersConfig as $name => $cfg) {
            $enabled = $cfg['enabled'] ?? false;
            $status = $enabled ? 'enabled' : 'disabled';
            $color = $enabled ? 'green' : 'dark_gray';
            CLI::write("  [{$status}] {$name}: " . ($cfg['description'] ?? $cfg['type'] ?? 'unknown'), $color);

            if ($enabled) {
                $provider = $manager->get($name);
                if ($provider) {
                    try {
                        $health = $provider->healthCheck();
                        $healthStatus = $health['status'] ?? 'unknown';
                        $healthColor = $healthStatus === 'ok' ? 'green' : 'red';
                        CLI::write("          Health: {$healthStatus}", $healthColor);
                    } catch (\Throwable $e) {
                        CLI::write("          Health: error - " . $e->getMessage(), 'red');
                    }
                }
            }
        }
    }
}
