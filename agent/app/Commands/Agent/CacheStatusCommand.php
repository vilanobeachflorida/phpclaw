<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Cache\CacheManager;
use App\Libraries\UI\TerminalUI;

class CacheStatusCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:cache:status';
    protected $description = 'Show cache statistics';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $cache = new CacheManager(new FileStorage());
        $status = $cache->getStatus();

        $ui->header('Cache Status');
        $ui->newLine();

        $enabled = $status['enabled'] ?? false;
        $ui->check('Cache enabled', $enabled);
        $ui->newLine();

        $rows = [];
        foreach ($status['categories'] ?? [] as $cat => $info) {
            $sizeMb = round(($info['size_bytes'] ?? 0) / 1024 / 1024, 2);
            $rows[] = [$cat, $info['entries'] ?? 0, "{$sizeMb} MB"];
        }

        if (!empty($rows)) {
            $ui->table(['Category', 'Entries', 'Size'], $rows, 'blue');
        }
        $ui->newLine();
    }
}
