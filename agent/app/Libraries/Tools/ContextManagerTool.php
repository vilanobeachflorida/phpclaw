<?php

namespace App\Libraries\Tools;

/**
 * Smart context management for long coding sessions.
 *
 * Helps the agent compress, stash, and recall working context to sustain
 * effectiveness across many tool calls without re-reading files.
 *
 * Actions:
 *   summarize     – compress a file or directory into key facts
 *   stash         – save current working context before switching tasks
 *   recall        – restore stashed context
 *   list_stashes  – list all saved stashes
 *   delete_stash  – remove a stash
 *   project_brief – one-call summary of the entire project
 */
class ContextManagerTool extends BaseTool
{
    protected string $name = 'context_manager';
    protected string $description = 'Compress, stash, and recall working context for long coding sessions';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled'    => true,
            'timeout'    => 15,
            'stash_dir'  => 'writable/agent/context_stash',
            'max_stashes' => 20,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'action'      => ['type' => 'string', 'required' => true, 'enum' => ['summarize', 'stash', 'recall', 'list_stashes', 'delete_stash', 'project_brief']],
            'path'        => ['type' => 'string', 'required' => false, 'description' => 'File or directory to summarize / project path'],
            'stash_name'  => ['type' => 'string', 'required' => false, 'description' => 'Name for stash (stash/recall/delete)'],
            'context'     => ['type' => 'string', 'required' => false, 'description' => 'Free-form context text to stash'],
            'files'       => ['type' => 'array',  'required' => false, 'description' => 'Array of file paths relevant to the stash'],
            'task'        => ['type' => 'string', 'required' => false, 'description' => 'Current task description (for stash)'],
            'max_depth'   => ['type' => 'int',    'required' => false, 'default' => 3, 'description' => 'Directory depth for project_brief'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        switch ($args['action']) {
            case 'summarize':
                if ($err = $this->requireArgs($args, ['path'])) return $err;
                return $this->summarize($args['path']);

            case 'stash':
                if ($err = $this->requireArgs($args, ['stash_name'])) return $err;
                return $this->stash($args);

            case 'recall':
                if ($err = $this->requireArgs($args, ['stash_name'])) return $err;
                return $this->recall($args['stash_name']);

            case 'list_stashes':
                return $this->listStashes();

            case 'delete_stash':
                if ($err = $this->requireArgs($args, ['stash_name'])) return $err;
                return $this->deleteStash($args['stash_name']);

            case 'project_brief':
                return $this->projectBrief($args['path'] ?? getcwd(), $args['max_depth'] ?? 3);

            default:
                return $this->error("Unknown action: {$args['action']}");
        }
    }

    // ── summarize ─────────────────────────────────────────────

    private function summarize(string $path): array
    {
        if (is_file($path)) {
            return $this->summarizeFile($path);
        }
        if (is_dir($path)) {
            return $this->summarizeDirectory($path);
        }
        return $this->error("Path not found: {$path}");
    }

    private function summarizeFile(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) return $this->error("Cannot read: {$path}");

        $lines    = explode("\n", $content);
        $lineCount = count($lines);
        $ext      = pathinfo($path, PATHINFO_EXTENSION);
        $size     = filesize($path);

        // Extract key structures
        $imports   = [];
        $exports   = [];
        $classes   = [];
        $functions = [];

        foreach ($lines as $i => $line) {
            // Imports
            if (preg_match('/^(?:use|import|from|require|include|#include)\s+(.+)/', $line, $m)) {
                $imports[] = trim($m[1], " ;'\"\r");
            }
            // Exports
            if (preg_match('/^(?:export|module\.exports)/', $line)) {
                $exports[] = ['line' => $i + 1, 'content' => trim($line)];
            }
            // Classes
            if (preg_match('/(?:class|struct|enum|interface|trait)\s+(\w+)/', $line, $m)) {
                $classes[] = ['name' => $m[1], 'line' => $i + 1];
            }
            // Functions
            if (preg_match('/(?:function|def|fn|func)\s+(\w+)/', $line, $m)) {
                $functions[] = ['name' => $m[1], 'line' => $i + 1];
            }
        }

        return $this->success([
            'type'       => 'file',
            'path'       => $path,
            'extension'  => $ext,
            'lines'      => $lineCount,
            'size_bytes' => $size,
            'imports'    => array_slice($imports, 0, 50),
            'exports'    => array_slice($exports, 0, 20),
            'classes'    => $classes,
            'functions'  => array_slice($functions, 0, 50),
        ]);
    }

    private function summarizeDirectory(string $path): array
    {
        $stats = ['files' => 0, 'dirs' => 0, 'by_extension' => [], 'total_size' => 0];
        $keyFiles = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $skip = ['vendor', 'node_modules', '.git', '__pycache__', 'target', 'build', 'dist', '.next', '.nuxt'];

        foreach ($iterator as $item) {
            $rel = str_replace($path . '/', '', $item->getPathname());

            // Skip vendor-like dirs
            $topDir = explode('/', $rel)[0];
            if (in_array($topDir, $skip)) {
                if ($item->isDir()) $iterator->next();
                continue;
            }

            if ($item->isDir()) {
                $stats['dirs']++;
            } else {
                $stats['files']++;
                $stats['total_size'] += $item->getSize();
                $ext = $item->getExtension();
                if ($ext) {
                    $stats['by_extension'][$ext] = ($stats['by_extension'][$ext] ?? 0) + 1;
                }

                // Track key files
                $basename = $item->getFilename();
                if (in_array($basename, ['README.md', 'package.json', 'composer.json', 'Cargo.toml', 'go.mod', 'pyproject.toml', 'Makefile', 'Dockerfile', '.env.example'])) {
                    $keyFiles[] = $rel;
                }
            }
        }

        arsort($stats['by_extension']);

        return $this->success([
            'type'         => 'directory',
            'path'         => $path,
            'files'        => $stats['files'],
            'dirs'         => $stats['dirs'],
            'total_size'   => $this->formatSize($stats['total_size']),
            'by_extension' => array_slice($stats['by_extension'], 0, 15, true),
            'key_files'    => $keyFiles,
        ]);
    }

    // ── stash / recall ────────────────────────────────────────

    private function stash(array $args): array
    {
        $name = $args['stash_name'];
        $dir  = $this->stashDir();

        $stash = [
            'name'       => $name,
            'task'       => $args['task'] ?? null,
            'context'    => $args['context'] ?? null,
            'files'      => $args['files'] ?? [],
            'created_at' => date('c'),
        ];

        // Auto-summarize referenced files
        $summaries = [];
        foreach ($stash['files'] as $f) {
            if (is_file($f)) {
                $s = $this->summarizeFile($f);
                if ($s['success']) {
                    $summaries[$f] = [
                        'lines'     => $s['data']['lines'],
                        'classes'   => array_column($s['data']['classes'], 'name'),
                        'functions' => array_column(array_slice($s['data']['functions'], 0, 20), 'name'),
                    ];
                }
            }
        }
        $stash['file_summaries'] = $summaries;

        $path = "{$dir}/{$name}.json";
        file_put_contents($path, json_encode($stash, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->success($stash, "Context stashed as: {$name}");
    }

    private function recall(string $name): array
    {
        $dir  = $this->stashDir();
        $path = "{$dir}/{$name}.json";

        if (!file_exists($path)) return $this->error("Stash not found: {$name}");

        $stash = json_decode(file_get_contents($path), true);
        if (!$stash) return $this->error("Failed to parse stash: {$name}");

        return $this->success($stash, "Context recalled: {$name}");
    }

    private function listStashes(): array
    {
        $dir = $this->stashDir();
        if (!is_dir($dir)) return $this->success(['stashes' => [], 'count' => 0]);

        $stashes = [];
        foreach (glob("{$dir}/*.json") as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data) continue;
            $stashes[] = [
                'name'       => $data['name'] ?? basename($file, '.json'),
                'task'       => $data['task'] ?? null,
                'files'      => count($data['files'] ?? []),
                'created_at' => $data['created_at'] ?? null,
            ];
        }

        return $this->success(['stashes' => $stashes, 'count' => count($stashes)]);
    }

    private function deleteStash(string $name): array
    {
        $path = "{$this->stashDir()}/{$name}.json";
        if (!file_exists($path)) return $this->error("Stash not found: {$name}");
        unlink($path);
        return $this->success(['deleted' => $name]);
    }

    // ── project brief ─────────────────────────────────────────

    private function projectBrief(string $path, int $maxDepth): array
    {
        if (!is_dir($path)) return $this->error("Directory not found: {$path}");

        // Build tree
        $tree = $this->buildTree($path, $maxDepth, 0);

        // Detect stack (reuse ProjectDetectTool logic inline)
        $languages  = [];
        $frameworks = [];
        $markers = [
            'composer.json' => ['PHP', 'Composer'],
            'package.json'  => ['JavaScript', 'Node.js'],
            'Cargo.toml'    => ['Rust', 'Cargo'],
            'go.mod'        => ['Go', null],
            'pyproject.toml'=> ['Python', null],
            'requirements.txt' => ['Python', 'pip'],
            'Gemfile'       => ['Ruby', 'Bundler'],
            'pubspec.yaml'  => ['Dart', 'Flutter'],
            'mix.exs'       => ['Elixir', 'Mix'],
            'pom.xml'       => ['Java', 'Maven'],
            'build.gradle'  => ['Java', 'Gradle'],
        ];

        foreach ($markers as $file => [$lang, $fw]) {
            if (file_exists("{$path}/{$file}")) {
                $languages[] = $lang;
                if ($fw) $frameworks[] = $fw;
            }
        }

        // Key files content snippets
        $keyContent = [];
        foreach (['README.md', 'CLAUDE.md', 'ARCHITECTURE.md'] as $readme) {
            if (file_exists("{$path}/{$readme}")) {
                $content = file_get_contents("{$path}/{$readme}");
                $keyContent[$readme] = mb_substr($content, 0, 2000);
            }
        }

        // Git info
        $gitInfo = null;
        if (is_dir("{$path}/.git")) {
            $branch = trim(@shell_exec("cd " . escapeshellarg($path) . " && git branch --show-current 2>/dev/null") ?: '');
            $lastCommit = trim(@shell_exec("cd " . escapeshellarg($path) . " && git log --oneline -1 2>/dev/null") ?: '');
            $remoteUrl = trim(@shell_exec("cd " . escapeshellarg($path) . " && git remote get-url origin 2>/dev/null") ?: '');
            $gitInfo = [
                'branch'      => $branch ?: null,
                'last_commit' => $lastCommit ?: null,
                'remote'      => $remoteUrl ?: null,
            ];
        }

        $dirSummary = $this->summarizeDirectory($path);

        return $this->success([
            'path'        => $path,
            'languages'   => array_unique($languages),
            'frameworks'  => $frameworks,
            'git'         => $gitInfo,
            'tree'        => $tree,
            'stats'       => $dirSummary['success'] ? $dirSummary['data'] : null,
            'key_content' => $keyContent,
        ]);
    }

    // ── helpers ───────────────────────────────────────────────

    private function buildTree(string $path, int $maxDepth, int $depth): array
    {
        if ($depth >= $maxDepth) return [];

        $skip = ['vendor', 'node_modules', '.git', '__pycache__', 'target', 'build', 'dist', '.next', '.nuxt', '.idea', '.vscode'];
        $items = [];

        $entries = @scandir($path);
        if (!$entries) return [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (in_array($entry, $skip)) {
                $items[] = ['name' => $entry . '/', 'type' => 'dir', 'skipped' => true];
                continue;
            }

            $full = "{$path}/{$entry}";
            if (is_dir($full)) {
                $children = $this->buildTree($full, $maxDepth, $depth + 1);
                $items[] = ['name' => $entry . '/', 'type' => 'dir', 'children' => $children];
            } else {
                $items[] = ['name' => $entry, 'type' => 'file', 'size' => filesize($full)];
            }
        }

        return $items;
    }

    private function stashDir(): string
    {
        $dir = WRITEPATH . 'agent/context_stash';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir;
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float)$bytes;
        while ($size >= 1024 && $i < 3) {
            $size /= 1024;
            $i++;
        }
        return round($size, 1) . ' ' . $units[$i];
    }
}
