<?php

namespace App\Libraries\Tools;

/**
 * Structured git operations without exposing raw shell access.
 * Returns parsed JSON output for cleaner LLM reasoning.
 */
class GitOpsTool extends BaseTool
{
    protected string $name = 'git_ops';
    protected string $description = 'Run structured git operations (status, diff, log, blame, branch, commit) with parsed output';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 30,
            'allowed_operations' => [], // empty = allow all
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'operation' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Git operation: status, diff, log, blame, branch, show, stash_list, tag',
            ],
            'path' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Repository path or file path (for blame). Defaults to cwd.',
            ],
            'ref' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Git ref (branch, tag, commit hash) for log/diff/show operations',
            ],
            'max_count' => [
                'type' => 'int',
                'required' => false,
                'description' => 'Maximum number of results for log (default: 20)',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['operation'])) return $err;

        $op = $args['operation'];
        $allowed = $this->config['allowed_operations'] ?? [];
        if (!empty($allowed) && !in_array($op, $allowed)) {
            return $this->error("Operation not allowed: {$op}");
        }

        $validOps = ['status', 'diff', 'log', 'blame', 'branch', 'show', 'stash_list', 'tag'];
        if (!in_array($op, $validOps)) {
            return $this->error("Unknown operation: {$op}. Valid: " . implode(', ', $validOps));
        }

        $repoPath = $args['path'] ?? getcwd();

        return match ($op) {
            'status'     => $this->gitStatus($repoPath),
            'diff'       => $this->gitDiff($repoPath, $args['ref'] ?? null),
            'log'        => $this->gitLog($repoPath, (int)($args['max_count'] ?? 20), $args['ref'] ?? null),
            'blame'      => $this->gitBlame($repoPath, $args['ref'] ?? null),
            'branch'     => $this->gitBranch($repoPath),
            'show'       => $this->gitShow($repoPath, $args['ref'] ?? 'HEAD'),
            'stash_list' => $this->gitStashList($repoPath),
            'tag'        => $this->gitTag($repoPath),
            default      => $this->error("Unhandled operation: {$op}"),
        };
    }

    private function run(string $cmd, string $cwd): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
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

    private function gitStatus(string $repoPath): array
    {
        $result = $this->run('git status --porcelain=v1 2>&1', $repoPath);
        if ($result['exit'] !== 0) {
            return $this->error("git status failed: " . trim($result['stderr'] ?: $result['stdout']));
        }

        $files = [];
        foreach (array_filter(explode("\n", trim($result['stdout']))) as $line) {
            if (strlen($line) < 4) continue;
            $status = trim(substr($line, 0, 2));
            $file = trim(substr($line, 3));
            $files[] = ['status' => $status, 'file' => $file];
        }

        // Get current branch
        $branch = $this->run('git branch --show-current 2>&1', $repoPath);

        return $this->success([
            'branch' => trim($branch['stdout']),
            'clean' => empty($files),
            'files' => $files,
            'file_count' => count($files),
        ]);
    }

    private function gitDiff(string $repoPath, ?string $ref): array
    {
        $cmd = $ref ? "git diff " . escapeshellarg($ref) . " 2>&1" : "git diff 2>&1";
        $result = $this->run($cmd, $repoPath);
        if ($result['exit'] !== 0) {
            return $this->error("git diff failed: " . trim($result['stderr']));
        }

        $stat = $this->run($ref ? "git diff --stat " . escapeshellarg($ref) . " 2>&1" : "git diff --stat 2>&1", $repoPath);

        return $this->success([
            'ref' => $ref,
            'diff' => $result['stdout'],
            'stat' => trim($stat['stdout']),
        ]);
    }

    private function gitLog(string $repoPath, int $maxCount, ?string $ref): array
    {
        $maxCount = min($maxCount, 100);
        $refArg = $ref ? ' ' . escapeshellarg($ref) : '';
        $format = '--format={"hash":"%H","short":"%h","author":"%an","date":"%aI","subject":"%s"}';
        $cmd = "git log {$format} -n {$maxCount}{$refArg} 2>&1";
        $result = $this->run($cmd, $repoPath);
        if ($result['exit'] !== 0) {
            return $this->error("git log failed: " . trim($result['stderr']));
        }

        $commits = [];
        foreach (array_filter(explode("\n", trim($result['stdout']))) as $line) {
            $decoded = json_decode($line, true);
            if ($decoded) $commits[] = $decoded;
        }

        return $this->success([
            'ref' => $ref,
            'commits' => $commits,
            'count' => count($commits),
        ]);
    }

    private function gitBlame(string $repoPath, ?string $file): array
    {
        if (!$file) {
            return $this->error("blame requires a file path in the 'ref' argument");
        }
        $fullPath = realpath($repoPath . '/' . $file) ?: $file;
        if (!file_exists($fullPath)) {
            return $this->error("File not found: {$file}");
        }

        $cmd = "git blame --porcelain " . escapeshellarg($file) . " 2>&1";
        $result = $this->run($cmd, $repoPath);
        if ($result['exit'] !== 0) {
            return $this->error("git blame failed: " . trim($result['stderr']));
        }

        return $this->success([
            'file' => $file,
            'blame' => $result['stdout'],
        ]);
    }

    private function gitBranch(string $repoPath): array
    {
        $result = $this->run('git branch -a --format="%(refname:short) %(objectname:short) %(upstream:short)" 2>&1', $repoPath);
        if ($result['exit'] !== 0) {
            return $this->error("git branch failed: " . trim($result['stderr']));
        }

        $current = trim($this->run('git branch --show-current 2>&1', $repoPath)['stdout']);
        $branches = [];
        foreach (array_filter(explode("\n", trim($result['stdout']))) as $line) {
            $parts = preg_split('/\s+/', trim($line), 3);
            $branches[] = [
                'name' => $parts[0] ?? '',
                'hash' => $parts[1] ?? '',
                'upstream' => $parts[2] ?? null,
                'current' => ($parts[0] ?? '') === $current,
            ];
        }

        return $this->success([
            'current' => $current,
            'branches' => $branches,
            'count' => count($branches),
        ]);
    }

    private function gitShow(string $repoPath, string $ref): array
    {
        $cmd = "git show --stat " . escapeshellarg($ref) . " 2>&1";
        $result = $this->run($cmd, $repoPath);
        if ($result['exit'] !== 0) {
            return $this->error("git show failed: " . trim($result['stderr']));
        }

        return $this->success([
            'ref' => $ref,
            'output' => $result['stdout'],
        ]);
    }

    private function gitStashList(string $repoPath): array
    {
        $result = $this->run('git stash list 2>&1', $repoPath);
        return $this->success([
            'stashes' => array_filter(explode("\n", trim($result['stdout']))),
        ]);
    }

    private function gitTag(string $repoPath): array
    {
        $result = $this->run('git tag --sort=-creatordate -n1 2>&1', $repoPath);
        $tags = [];
        foreach (array_filter(explode("\n", trim($result['stdout']))) as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);
            $tags[] = ['tag' => $parts[0] ?? '', 'message' => $parts[1] ?? ''];
        }
        return $this->success(['tags' => $tags, 'count' => count($tags)]);
    }
}
