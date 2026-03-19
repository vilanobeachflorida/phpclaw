<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;

/**
 * Start a dedicated HTTP server for the PHPClaw API.
 *
 * Uses PHP's built-in development server, scoped to the project's
 * public/ directory. Can be configured via writable/agent/config/api.json
 * or overridden with CLI options.
 */
class ApiServeCommand extends BaseCommand
{
    protected $group       = 'agent';
    protected $name        = 'agent:api:serve';
    protected $description = 'Start the API HTTP server';
    protected $usage       = 'agent:api:serve [--host <host>] [--port <port>]';

    public function run(array $params)
    {
        $storage = new FileStorage();
        $config  = new ConfigLoader($storage);
        $apiConf = $config->load('api');

        // Check if API is enabled
        if (!($apiConf['enabled'] ?? true)) {
            CLI::error('API is disabled. Set "enabled": true in writable/agent/config/api.json');
            return;
        }

        // Check if server is enabled in config
        $serverConf = $apiConf['server'] ?? [];
        if (!($serverConf['enabled'] ?? true)) {
            CLI::error('API server is disabled in config.');
            CLI::write('Enable it with: php spark agent:api:serve --enable', 'light_gray');
            CLI::write('Or set server.enabled = true in writable/agent/config/api.json', 'light_gray');
            return;
        }

        // Handle --enable / --disable flags
        if (CLI::getOption('enable') !== null) {
            $apiConf['server'] = array_merge($serverConf, ['enabled' => true]);
            $config->save('api', $apiConf);
            CLI::write('API server enabled.', 'green');
            if (count($params) === 0) {
                // If just enabling, also start the server
                $serverConf['enabled'] = true;
            }
        }
        if (CLI::getOption('disable') !== null) {
            $apiConf['server'] = array_merge($serverConf, ['enabled' => false]);
            $config->save('api', $apiConf);
            CLI::write('API server disabled.', 'yellow');
            return;
        }

        // Check for a token
        $token = $apiConf['token'] ?? null;
        if (empty($token)) {
            CLI::write('No API token configured. Generating one now...', 'yellow');
            CLI::newLine();
            $token = bin2hex(random_bytes(32));
            $apiConf['token'] = $token;
            $config->save('api', $apiConf);
            CLI::write('Token: ' . $token, 'yellow');
            CLI::newLine();
        }

        // Resolve host and port: CLI flags > config > defaults
        $host = CLI::getOption('host') ?? $serverConf['host'] ?? '0.0.0.0';
        $port = CLI::getOption('port') ?? $serverConf['port'] ?? 8081;
        $port = (int) $port;

        // Save resolved settings back to config for consistency
        $apiConf['server'] = array_merge($serverConf, [
            'enabled' => true,
            'host'    => $host,
            'port'    => $port,
        ]);
        $config->save('api', $apiConf);

        // Resolve the document root (public/ directory)
        $docRoot = realpath(FCPATH) ?: ROOTPATH . 'public';
        if (!is_dir($docRoot)) {
            CLI::error("Document root not found: {$docRoot}");
            return;
        }

        CLI::newLine();
        CLI::write('╔══════════════════════════════════════════════╗', 'light_cyan');
        CLI::write('║         PHPClaw API Server                   ║', 'light_cyan');
        CLI::write('╚══════════════════════════════════════════════╝', 'light_cyan');
        CLI::newLine();

        CLI::write('  Host:    ' . $host, 'white');
        CLI::write('  Port:    ' . $port, 'white');
        CLI::write('  Docs:    http://' . ($host === '0.0.0.0' ? 'localhost' : $host) . ':' . $port . '/api/docs', 'light_blue');
        CLI::write('  Status:  http://' . ($host === '0.0.0.0' ? 'localhost' : $host) . ':' . $port . '/api/status', 'light_blue');
        CLI::newLine();
        CLI::write('  Token:   ' . substr($token, 0, 12) . '...' . substr($token, -8), 'yellow');
        CLI::newLine();
        CLI::write('  Press Ctrl+C to stop.', 'light_gray');
        CLI::newLine();

        // Start PHP's built-in server
        $command = sprintf(
            'php -S %s:%d -t %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($docRoot)
        );

        passthru($command);
    }
}
