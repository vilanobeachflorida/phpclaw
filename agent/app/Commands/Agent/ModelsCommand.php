<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Service\ProviderManager;
use App\Libraries\UI\TerminalUI;

class ModelsCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:models';
    protected $description = 'List available models from providers';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $config = new ConfigLoader();
        $manager = new ProviderManager($config);
        $manager->loadAll();

        $ui->header('Available Models');

        foreach ($manager->all() as $name => $provider) {
            $ui->newLine();
            $ui->divider($name, 'bright_cyan');

            try {
                $models = $provider->listModels();
                if (empty($models)) {
                    $ui->dim('No models listed');
                } else {
                    foreach ($models as $m) {
                        $ui->bullet($m['name'] ?? 'unknown', 'white');
                    }
                }
            } catch (\Throwable $e) {
                $ui->error($e->getMessage());
            }
        }
        $ui->newLine();
    }
}
