<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Memory\MemoryManager;
use App\Libraries\Cache\CacheManager;

class MaintainCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:maintain';
    protected $description = 'Run all maintenance tasks';

    public function run(array $params)
    {
        $storage = new FileStorage();

        CLI::write('=== Running Maintenance ===', 'green');
        CLI::newLine();

        // Memory compaction
        CLI::write('Memory compaction...', 'yellow');
        $memory = new MemoryManager($storage);
        $result = $memory->compactGlobalMemory();
        if ($result['compacted'] ?? false) {
            CLI::write("  Compacted {$result['note_count']} notes.", 'green');
        } else {
            CLI::write("  Nothing to compact.", 'light_gray');
        }

        // Cache pruning
        CLI::write('Cache pruning...', 'yellow');
        $cache = new CacheManager($storage);
        $pruneResult = $cache->prune();
        CLI::write("  Checked: {$pruneResult['checked']}, Pruned: {$pruneResult['pruned']}");

        // Log maintenance
        $storage->appendNdjson('logs/maintenance.ndjson', [
            'timestamp' => date('c'),
            'type' => 'manual',
            'memory_result' => $result,
            'cache_result' => $pruneResult,
        ]);

        CLI::newLine();
        CLI::write('Maintenance complete.', 'green');
    }
}
