<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Service\ServiceLoop;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;

/**
 * Start the PHPClaw agent: API server + background service loop.
 *
 * Launches the HTTP server as a background process, then runs
 * the service loop (heartbeat, tasks, maintenance) in the foreground.
 */
class ServeCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:serve';
    protected $description = 'Start the agent (API server + service loop)';
    protected $usage = 'agent:serve [--host <host>] [--port <port>] [--no-api]';

    public function run(array $params)
    {
        $skipApi = CLI::getOption('no-api') !== null;

        $storage = new FileStorage();
        $config  = new ConfigLoader($storage);
        $apiConf = $config->load('api');

        $serverConf = $apiConf['server'] ?? [];
        $host = CLI::getOption('host') ?? $serverConf['host'] ?? '0.0.0.0';
        $port = CLI::getOption('port') ?? $serverConf['port'] ?? 8081;
        $port = (int) $port;
        $token = $apiConf['token'] ?? null;

        CLI::newLine();
        CLI::write('╔══════════════════════════════════════════════╗', 'light_cyan');
        CLI::write('║           PHPClaw Agent Service               ║', 'light_cyan');
        CLI::write('╚══════════════════════════════════════════════╝', 'light_cyan');
        CLI::newLine();

        $apiPid = null;

        if (!$skipApi && ($apiConf['enabled'] ?? true) && ($serverConf['enabled'] ?? true)) {
            // Auto-generate token if missing
            if (empty($token)) {
                $token = bin2hex(random_bytes(32));
                $apiConf['token'] = $token;
                $config->save('api', $apiConf);
                CLI::write('  Generated API token: ' . $token, 'yellow');
                CLI::newLine();
            }

            // Start the HTTP server as a background process
            $docRoot = realpath(FCPATH) ?: ROOTPATH . 'public';
            $rewrite = realpath(ROOTPATH . 'system/rewrite.php');
            $phpBin  = PHP_BINARY;

            $serverCmd = sprintf(
                '%s -S %s:%d -t %s %s > /dev/null 2>&1 & echo $!',
                escapeshellarg($phpBin),
                escapeshellarg($host),
                $port,
                escapeshellarg($docRoot),
                $rewrite ? escapeshellarg($rewrite) : ''
            );

            $apiPid = trim(shell_exec($serverCmd));

            if ($apiPid && is_numeric($apiPid)) {
                $displayHost = ($host === '0.0.0.0') ? 'localhost' : $host;
                CLI::write('  API Server:  http://' . $displayHost . ':' . $port, 'green');
                CLI::write('  API Docs:    http://' . $displayHost . ':' . $port . '/api/docs', 'light_blue');
                CLI::write('  API Token:   ' . substr($token, 0, 12) . '...' . substr($token, -8), 'yellow');
                CLI::write('  API PID:     ' . $apiPid, 'light_gray');
            } else {
                CLI::write('  API Server:  failed to start', 'red');
                $apiPid = null;
            }
        } else {
            $reason = $skipApi ? '(--no-api flag)' : '(disabled in config)';
            CLI::write("  API Server:  skipped {$reason}", 'light_gray');
        }

        CLI::write('  Service:     starting...', 'green');
        CLI::newLine();
        CLI::write('  Press Ctrl+C to stop.', 'light_gray');
        CLI::newLine();

        // Register shutdown to kill the API server when service stops
        if ($apiPid) {
            $pid = (int) $apiPid;
            register_shutdown_function(function () use ($pid) {
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGTERM);
                }
            });

            if (function_exists('pcntl_signal')) {
                $handler = function () use ($pid) {
                    if (posix_kill($pid, 0)) {
                        posix_kill($pid, SIGTERM);
                    }
                    exit(0);
                };
                pcntl_signal(SIGTERM, $handler);
                pcntl_signal(SIGINT, $handler);
            }
        }

        // Run the service loop in the foreground
        $service = new ServiceLoop();
        $service->start();
    }
}
