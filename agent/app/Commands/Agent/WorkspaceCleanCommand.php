<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Clean the agent workspace and all user-generated content.
 *
 * Removes everything from:
 *   - writable/agent/workspace/    (project output)
 *   - writable/agent/plans/        (task planner data)
 *   - writable/agent/context_stash/ (stashed contexts)
 *   - writable/agent/generated/    (generated images, etc.)
 *   - writable/agent/sessions/     (conversation history)
 *   - writable/agent/memory/       (agent memory)
 *   - writable/agent/cache/        (response cache)
 *   - writable/agent/processes/    (background process state)
 *   - writable/agent/schedules/    (cron schedules)
 *
 * Use --workspace to only clean the workspace output folder.
 * Use --all to clean everything (full factory wipe of runtime data).
 */
class WorkspaceCleanCommand extends BaseCommand
{
    protected $group       = 'agent';
    protected $name        = 'agent:workspace:clean';
    protected $description = 'Clean workspace output and optionally all user-generated content';
    protected $usage       = 'agent:workspace:clean [--all] [--yes]';

    public function run(array $params)
    {
        $cleanAll = CLI::getOption('all') !== null;
        $autoYes  = CLI::getOption('yes') !== null || CLI::getOption('y') !== null;

        $basePath = WRITEPATH . 'agent/';

        // Always clean workspace
        $targets = [
            'workspace'     => 'Project output (workspace/)',
        ];

        if ($cleanAll) {
            $targets = array_merge($targets, [
                'plans'         => 'Task plans (plans/)',
                'context_stash' => 'Stashed contexts (context_stash/)',
                'generated'     => 'Generated files (generated/)',
                'sessions'      => 'Session transcripts (sessions/)',
                'memory'        => 'Agent memory (memory/)',
                'cache'         => 'Response cache (cache/)',
                'processes'     => 'Process state (processes/)',
                'schedules'     => 'Cron schedules (schedules/)',
                'tasks'         => 'Background tasks (tasks/)',
                'locks'         => 'File locks (locks/)',
                'queues'        => 'Task queues (queues/)',
            ]);
        }

        // Show what will be cleaned
        $scope = $cleanAll ? 'ALL user-generated content' : 'workspace output only';
        CLI::write("Cleaning: {$scope}", 'yellow');
        CLI::newLine();

        foreach ($targets as $dir => $desc) {
            $fullPath = $basePath . $dir;
            $exists = is_dir($fullPath);
            $status = $exists ? $this->countItems($fullPath) . ' items' : 'empty';
            CLI::write("  {$desc} — {$status}", $exists ? 'white' : 'light_gray');
        }

        CLI::newLine();

        if (!$autoYes) {
            $confirm = CLI::prompt('This will permanently delete these files. Continue?', ['y', 'n']);
            if (strtolower($confirm) !== 'y') {
                CLI::write('Aborted.', 'light_gray');
                return;
            }
        }

        $totalRemoved = 0;

        foreach ($targets as $dir => $desc) {
            $fullPath = $basePath . $dir;
            if (is_dir($fullPath)) {
                $removed = $this->cleanDirectory($fullPath);
                $totalRemoved += $removed;
                CLI::write("  ✓ {$dir}/ — {$removed} items removed", 'green');
            }
        }

        CLI::newLine();
        CLI::write("Cleaned {$totalRemoved} items.", 'green');

        if ($cleanAll) {
            CLI::write('All user-generated content has been removed.', 'light_gray');
            CLI::write('Config files are preserved. Use agent:config:reset to reset those.', 'light_gray');
        }
    }

    /**
     * Remove all contents of a directory but keep the directory itself.
     */
    private function cleanDirectory(string $path): int
    {
        if (!is_dir($path)) return 0;

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count files in a directory (non-recursive, quick estimate).
     */
    private function countItems(string $path): int
    {
        if (!is_dir($path)) return 0;

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            $count++;
        }

        return $count;
    }
}
