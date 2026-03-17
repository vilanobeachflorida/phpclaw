<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Service\ServiceLoop;

/**
 * Start the long-lived agent service loop.
 */
class ServeCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:serve';
    protected $description = 'Start the agent service loop';

    public function run(array $params)
    {
        CLI::write('Starting PHPClaw service loop...', 'green');
        CLI::write('Press Ctrl+C to stop.', 'light_gray');

        $service = new ServiceLoop();
        $service->start();
    }
}
