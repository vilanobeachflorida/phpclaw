<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Cache\CacheManager;

class CacheClearCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:cache:clear';
    protected $description = 'Clear all cache';

    public function run(array $params)
    {
        $cache = new CacheManager(new FileStorage());
        $cleared = $cache->clearAll();
        CLI::write("Cache cleared: {$cleared} entries removed.", 'green');
    }
}
