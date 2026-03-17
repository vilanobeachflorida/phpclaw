<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Cache\CacheManager;

class CacheStatusCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:cache:status';
    protected $description = 'Show cache statistics';

    public function run(array $params)
    {
        $cache = new CacheManager(new FileStorage());
        $status = $cache->getStatus();

        CLI::write('=== Cache Status ===', 'green');
        CLI::write('Enabled: ' . ($status['enabled'] ? 'yes' : 'no'));
        CLI::newLine();

        foreach ($status['categories'] as $cat => $info) {
            $sizeMb = round($info['size_bytes'] / 1024 / 1024, 2);
            CLI::write("  {$cat}: {$info['entries']} entries, {$sizeMb} MB");
        }
    }
}
