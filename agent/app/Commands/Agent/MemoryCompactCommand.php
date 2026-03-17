<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Memory\MemoryManager;

class MemoryCompactCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:memory:compact';
    protected $description = 'Run memory compaction';

    public function run(array $params)
    {
        CLI::write('Running memory compaction...', 'yellow');

        $memory = new MemoryManager(new FileStorage());
        $result = $memory->compactGlobalMemory();

        if ($result['compacted'] ?? false) {
            CLI::write("Compacted {$result['note_count']} notes.", 'green');
            CLI::write("Artifact: {$result['artifact']}");
        } else {
            CLI::write("Nothing to compact: " . ($result['reason'] ?? 'unknown'), 'light_gray');
        }
    }
}
