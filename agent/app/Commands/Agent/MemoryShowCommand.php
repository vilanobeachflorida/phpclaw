<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Memory\MemoryManager;

class MemoryShowCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:memory:show';
    protected $description = 'Show memory statistics and recent notes';

    public function run(array $params)
    {
        $memory = new MemoryManager(new FileStorage());
        $stats = $memory->getStats();

        CLI::write('=== Memory ===', 'green');
        CLI::write("Global notes: {$stats['global_notes']}");
        CLI::write("Summaries: {$stats['total_summaries']}");
        CLI::write("Compactions: {$stats['compaction_count']}");
        CLI::write("Last compaction: " . ($stats['last_compaction'] ?? 'never'));

        CLI::newLine();
        $summary = $memory->getGlobalSummary();
        if ($summary) {
            CLI::write('--- Global Summary ---', 'yellow');
            CLI::write($summary);
        }

        CLI::newLine();
        $notes = $memory->getGlobalNotes();
        $recent = array_slice($notes, -10);
        if (!empty($recent)) {
            CLI::write('--- Recent Notes ---', 'yellow');
            foreach ($recent as $note) {
                $content = $note['content'] ?? $note['text'] ?? '(empty)';
                CLI::write("  [{$note['timestamp']}] " . mb_substr($content, 0, 120), 'light_gray');
            }
        }
    }
}
