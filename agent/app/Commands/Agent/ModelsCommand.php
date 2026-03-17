<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Service\ProviderManager;

class ModelsCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:models';
    protected $description = 'List available models from providers';

    public function run(array $params)
    {
        $config = new ConfigLoader();
        $manager = new ProviderManager($config);
        $manager->loadAll();

        CLI::write('=== Available Models ===', 'green');
        CLI::newLine();

        foreach ($manager->all() as $name => $provider) {
            CLI::write("Provider: {$name}", 'cyan');
            try {
                $models = $provider->listModels();
                if (empty($models)) {
                    CLI::write('  (no models listed)');
                } else {
                    foreach ($models as $m) {
                        CLI::write("  - {$m['name']}");
                    }
                }
            } catch (\Throwable $e) {
                CLI::write("  Error: " . $e->getMessage(), 'red');
            }
            CLI::newLine();
        }
    }
}
