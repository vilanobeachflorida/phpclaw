<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Memory\MemoryManager;
use App\Libraries\UI\TerminalUI;

class MemoryShowCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:memory:show';
    protected $description = 'Show memory statistics and recent notes';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $memory = new MemoryManager(new FileStorage());
        $stats = $memory->getStats();

        $ui->header('Memory');
        $ui->newLine();

        $ui->keyValue([
            'Global notes'     => $stats['global_notes'] ?? 0,
            'Summaries'        => $stats['total_summaries'] ?? 0,
            'Compactions'      => $stats['compaction_count'] ?? 0,
            'Last compaction'  => $stats['last_compaction'] ?? $ui->style('never', 'gray'),
        ]);

        $summary = $memory->getGlobalSummary();
        if ($summary) {
            $ui->newLine();
            $ui->divider('Global Summary', 'bright_yellow');
            $ui->newLine();
            $ui->write("  {$summary}", 'white');
        }

        $notes = $memory->getGlobalNotes();
        $recent = array_slice($notes, -10);
        if (!empty($recent)) {
            $ui->newLine();
            $ui->divider('Recent Notes', 'bright_yellow');
            $ui->newLine();
            foreach ($recent as $note) {
                $content = $note['content'] ?? $note['text'] ?? '(empty)';
                $time = $note['timestamp'] ?? '';
                $ui->inline($ui->style("  [{$time}] ", 'gray'));
                echo mb_substr($content, 0, 120) . "\n";
            }
        }
        $ui->newLine();
    }
}
