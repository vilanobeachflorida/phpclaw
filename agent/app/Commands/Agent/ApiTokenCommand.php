<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;

/**
 * Generate or display the API authentication token.
 */
class ApiTokenCommand extends BaseCommand
{
    protected $group       = 'agent';
    protected $name        = 'agent:api:token';
    protected $description = 'Generate or show the API bearer token';
    protected $usage       = 'agent:api:token [--regenerate]';

    public function run(array $params)
    {
        $storage = new FileStorage();
        $config  = new ConfigLoader($storage);
        $apiConf = $config->load('api');

        $regenerate = CLI::getOption('regenerate') !== null
            || in_array('--regenerate', $params, true);

        $currentToken = $apiConf['token'] ?? null;

        if ($currentToken && !$regenerate) {
            CLI::write('Current API token:', 'green');
            CLI::write($currentToken, 'yellow');
            CLI::newLine();
            CLI::write('Use --regenerate to create a new token.', 'light_gray');
            return;
        }

        // Generate a secure random token
        $token = bin2hex(random_bytes(32));
        $apiConf['token'] = $token;
        $config->save('api', $apiConf);

        if ($currentToken) {
            CLI::write('API token regenerated.', 'green');
        } else {
            CLI::write('API token generated.', 'green');
        }

        CLI::newLine();
        CLI::write('Token: ' . $token, 'yellow');
        CLI::newLine();
        CLI::write('Use this in your requests:', 'light_gray');
        CLI::write('  curl -H "Authorization: Bearer ' . $token . '" http://localhost:8080/api/status', 'light_gray');
    }
}
