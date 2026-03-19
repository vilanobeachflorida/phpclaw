<?php

namespace App\Libraries\Tools;

/**
 * Code diff analyzer that produces structured diffs with per-hunk context.
 * Works with files or git refs.
 */
class DiffReviewTool extends BaseTool
{
    protected string $name = 'diff_review';
    protected string $description = 'Analyze code diffs between files or git refs with structured per-hunk output';

    public function getInputSchema(): array
    {
        return [
            'mode' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Diff mode: "files" (compare two files), "git" (compare git refs), "staged" (show staged changes), "working" (show unstaged changes)',
            ],
            'path_a' => [
                'type' => 'string',
                'required' => false,
                'description' => 'First file path (for files mode) or base ref (for git mode)',
            ],
            'path_b' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Second file path (for files mode) or head ref (for git mode)',
            ],
            'context_lines' => [
                'type' => 'int',
                'required' => false,
                'description' => 'Number of context lines around changes (default: 3)',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['mode'])) return $err;

        $mode = $args['mode'];
        $contextLines = (int)($args['context_lines'] ?? 3);

        return match ($mode) {
            'files'   => $this->diffFiles($args['path_a'] ?? '', $args['path_b'] ?? '', $contextLines),
            'git'     => $this->diffGit($args['path_a'] ?? 'HEAD~1', $args['path_b'] ?? 'HEAD', $contextLines),
            'staged'  => $this->diffStaged($contextLines),
            'working' => $this->diffWorking($contextLines),
            default   => $this->error("Unknown mode: {$mode}. Use: files, git, staged, working"),
        };
    }

    private function diffFiles(string $pathA, string $pathB, int $context): array
    {
        if (!$pathA || !$pathB) {
            return $this->error("files mode requires both path_a and path_b");
        }
        if (!file_exists($pathA)) return $this->error("File not found: {$pathA}");
        if (!file_exists($pathB)) return $this->error("File not found: {$pathB}");

        $cmd = sprintf('diff -u -U%d %s %s 2>&1',
            $context,
            escapeshellarg($pathA),
            escapeshellarg($pathB)
        );

        $output = $this->run($cmd);
        $hunks = $this->parseHunks($output['stdout']);

        return $this->success([
            'mode' => 'files',
            'file_a' => $pathA,
            'file_b' => $pathB,
            'diff' => $output['stdout'],
            'hunks' => $hunks,
            'hunk_count' => count($hunks),
            'has_changes' => !empty($hunks),
        ]);
    }

    private function diffGit(string $baseRef, string $headRef, int $context): array
    {
        $cmd = sprintf('git diff -U%d %s...%s --stat 2>&1',
            $context,
            escapeshellarg($baseRef),
            escapeshellarg($headRef)
        );
        $stat = $this->run($cmd);

        $cmd = sprintf('git diff -U%d %s...%s 2>&1',
            $context,
            escapeshellarg($baseRef),
            escapeshellarg($headRef)
        );
        $diff = $this->run($cmd);

        if ($diff['exit'] !== 0) {
            return $this->error("git diff failed: " . trim($diff['stderr'] ?: $diff['stdout']));
        }

        $hunks = $this->parseHunks($diff['stdout']);
        $files = $this->parseFilesChanged($stat['stdout']);

        return $this->success([
            'mode' => 'git',
            'base_ref' => $baseRef,
            'head_ref' => $headRef,
            'files_changed' => $files,
            'file_count' => count($files),
            'hunks' => $hunks,
            'hunk_count' => count($hunks),
            'stat' => trim($stat['stdout']),
            'diff' => $diff['stdout'],
        ]);
    }

    private function diffStaged(int $context): array
    {
        $cmd = "git diff --cached -U{$context} --stat 2>&1";
        $stat = $this->run($cmd);

        $cmd = "git diff --cached -U{$context} 2>&1";
        $diff = $this->run($cmd);

        $hunks = $this->parseHunks($diff['stdout']);

        return $this->success([
            'mode' => 'staged',
            'diff' => $diff['stdout'],
            'stat' => trim($stat['stdout']),
            'hunks' => $hunks,
            'hunk_count' => count($hunks),
            'has_changes' => !empty($hunks),
        ]);
    }

    private function diffWorking(int $context): array
    {
        $cmd = "git diff -U{$context} --stat 2>&1";
        $stat = $this->run($cmd);

        $cmd = "git diff -U{$context} 2>&1";
        $diff = $this->run($cmd);

        $hunks = $this->parseHunks($diff['stdout']);

        return $this->success([
            'mode' => 'working',
            'diff' => $diff['stdout'],
            'stat' => trim($stat['stdout']),
            'hunks' => $hunks,
            'hunk_count' => count($hunks),
            'has_changes' => !empty($hunks),
        ]);
    }

    private function run(string $cmd): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, getcwd());
        if (!is_resource($process)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'Failed to start process'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function parseHunks(string $diff): array
    {
        $hunks = [];
        $currentHunk = null;

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('/^@@\s+\-(\d+),?(\d*)\s+\+(\d+),?(\d*)\s+@@(.*)$/', $line, $m)) {
                if ($currentHunk) $hunks[] = $currentHunk;
                $currentHunk = [
                    'header' => $line,
                    'old_start' => (int)$m[1],
                    'old_count' => $m[2] !== '' ? (int)$m[2] : 1,
                    'new_start' => (int)$m[3],
                    'new_count' => $m[4] !== '' ? (int)$m[4] : 1,
                    'context' => trim($m[5]),
                    'additions' => 0,
                    'deletions' => 0,
                    'lines' => [],
                ];
            } elseif ($currentHunk !== null) {
                $currentHunk['lines'][] = $line;
                if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                    $currentHunk['additions']++;
                } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                    $currentHunk['deletions']++;
                }
            }
        }

        if ($currentHunk) $hunks[] = $currentHunk;
        return $hunks;
    }

    private function parseFilesChanged(string $stat): array
    {
        $files = [];
        foreach (explode("\n", $stat) as $line) {
            if (preg_match('/^\s*(.+?)\s+\|\s+(\d+)\s+([+\-]+)/', $line, $m)) {
                $files[] = [
                    'file' => trim($m[1]),
                    'changes' => (int)$m[2],
                    'bar' => $m[3],
                ];
            }
        }
        return $files;
    }
}
