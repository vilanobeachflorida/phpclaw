<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Cache\CacheManager;

class CachePruneCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:cache:prune';
    protected $description = 'Prune expired cache entries';

    public function run(array $params)
    {
        $cache = new CacheManager(new FileStorage());
        $result = $cache->prune();
        CLI::write("Checked: {$result['checked']}, Pruned: {$result['pruned']}", 'green');
    }
}
