<?php

namespace App\Libraries\Tools;

/**
 * Language-agnostic code intelligence via universal-ctags or regex fallback.
 *
 * Provides symbol indexing and navigation across any language without
 * embedding language-specific parsers.
 *
 * Actions:
 *   index           – build/update symbol index for the project
 *   find_definition – locate where a symbol is defined
 *   find_references – find all usages of a symbol
 *   list_symbols    – list symbols in a file
 *   outline         – structural overview of a file
 */
class CodeSymbolsTool extends BaseTool
{
    protected string $name = 'code_symbols';
    protected string $description = 'Language-agnostic code intelligence: find definitions, references, and symbol outlines';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled'    => true,
            'timeout'    => 30,
            'tags_file'  => '.tags',
            'use_ctags'  => true,
            'max_results' => 100,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'action' => ['type' => 'string', 'required' => true, 'enum' => ['index', 'find_definition', 'find_references', 'list_symbols', 'outline']],
            'path'   => ['type' => 'string', 'required' => false, 'description' => 'Project dir (index) or file path (outline/list_symbols)'],
            'symbol' => ['type' => 'string', 'required' => false, 'description' => 'Symbol name to find'],
            'kind'   => ['type' => 'string', 'required' => false, 'description' => 'Filter by kind: function, class, method, variable, type, etc.'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        switch ($args['action']) {
            case 'index':
                return $this->buildIndex($args['path'] ?? getcwd());
            case 'find_definition':
                if ($err = $this->requireArgs($args, ['symbol'])) return $err;
                return $this->findDefinition($args['symbol'], $args['path'] ?? getcwd(), $args['kind'] ?? null);
            case 'find_references':
                if ($err = $this->requireArgs($args, ['symbol'])) return $err;
                return $this->findReferences($args['symbol'], $args['path'] ?? getcwd());
            case 'list_symbols':
                return $this->listSymbols($args['path'] ?? getcwd(), $args['kind'] ?? null);
            case 'outline':
                if ($err = $this->requireArgs($args, ['path'])) return $err;
                return $this->outline($args['path']);
            default:
                return $this->error("Unknown action: {$args['action']}");
        }
    }

    // ── index ─────────────────────────────────────────────────

    private function buildIndex(string $path): array
    {
        if (!is_dir($path)) return $this->error("Directory not found: {$path}");

        $tagsFile = "{$path}/{$this->config['tags_file']}";

        // Try universal-ctags first
        if ($this->config['use_ctags'] && $this->hasCTags()) {
            $cmd = "ctags -R --fields=+lnKS --extras=+q --output-format=json -f " . escapeshellarg($tagsFile) . " " . escapeshellarg($path);
            $result = $this->shell($cmd, $path);
            if ($result['exit_code'] === 0) {
                $count = 0;
                if (file_exists($tagsFile)) {
                    $count = count(file($tagsFile, FILE_SKIP_EMPTY_LINES));
                }
                return $this->success([
                    'method'   => 'ctags',
                    'tags_file' => $tagsFile,
                    'symbols'  => $count,
                ]);
            }
            // Fall through to regex
        }

        // Fallback: basic regex index
        $symbols = $this->regexIndex($path);
        $json = json_encode($symbols, JSON_PRETTY_PRINT);
        file_put_contents($tagsFile, $json);

        return $this->success([
            'method'   => 'regex',
            'tags_file' => $tagsFile,
            'symbols'  => count($symbols),
        ]);
    }

    // ── find definition ───────────────────────────────────────

    private function findDefinition(string $symbol, string $path, ?string $kind = null): array
    {
        // Try tags file first
        $tagsFile = is_dir($path) ? "{$path}/{$this->config['tags_file']}" : dirname($path) . "/{$this->config['tags_file']}";
        $projectDir = is_dir($path) ? $path : dirname($path);

        if (file_exists($tagsFile)) {
            $results = $this->searchTags($tagsFile, $symbol, $kind);
            if (!empty($results)) {
                return $this->success(['symbol' => $symbol, 'definitions' => $results, 'method' => 'tags']);
            }
        }

        // Fallback: grep for common definition patterns
        $results = $this->grepDefinitions($symbol, $projectDir, $kind);
        return $this->success(['symbol' => $symbol, 'definitions' => $results, 'method' => 'grep']);
    }

    // ── find references ───────────────────────────────────────

    private function findReferences(string $symbol, string $path): array
    {
        $projectDir = is_dir($path) ? $path : dirname($path);
        $results = [];
        $maxResults = $this->config['max_results'];

        // Use grep to find all occurrences
        $escaped = preg_quote($symbol, '/');
        $cmd = "grep -rnw " . escapeshellarg($symbol) . " " . escapeshellarg($projectDir) .
               " --include='*.php' --include='*.js' --include='*.ts' --include='*.tsx'" .
               " --include='*.py' --include='*.go' --include='*.rs' --include='*.java'" .
               " --include='*.rb' --include='*.swift' --include='*.kt' --include='*.ex'" .
               " --include='*.c' --include='*.cpp' --include='*.h' --include='*.cs'" .
               " --include='*.lua' --include='*.zig' --include='*.dart'" .
               " 2>/dev/null | head -n {$maxResults}";

        $output = $this->shell($cmd, $projectDir);
        $lines = array_filter(explode("\n", trim($output['stdout'] ?? '')));

        foreach ($lines as $line) {
            if (preg_match('/^(.+?):(\d+):(.+)$/', $line, $m)) {
                $results[] = [
                    'file'    => $m[1],
                    'line'    => (int)$m[2],
                    'content' => trim($m[3]),
                ];
            }
        }

        return $this->success(['symbol' => $symbol, 'references' => $results, 'count' => count($results)]);
    }

    // ── list symbols ──────────────────────────────────────────

    private function listSymbols(string $path, ?string $kind = null): array
    {
        if (is_file($path)) {
            // For a single file, use ctags on that file or regex
            if ($this->config['use_ctags'] && $this->hasCTags()) {
                $cmd = "ctags --output-format=json --fields=+lnKS -f - " . escapeshellarg($path) . " 2>/dev/null";
                $result = $this->shell($cmd);
                $symbols = $this->parseCtagsJson($result['stdout'] ?? '');
                if ($kind) {
                    $symbols = array_filter($symbols, fn($s) => strcasecmp($s['kind'] ?? '', $kind) === 0);
                    $symbols = array_values($symbols);
                }
                return $this->success(['file' => $path, 'symbols' => $symbols, 'count' => count($symbols)]);
            }
            // Regex fallback for single file
            $symbols = $this->regexSymbolsForFile($path);
            if ($kind) {
                $symbols = array_filter($symbols, fn($s) => strcasecmp($s['kind'] ?? '', $kind) === 0);
                $symbols = array_values($symbols);
            }
            return $this->success(['file' => $path, 'symbols' => $symbols, 'count' => count($symbols)]);
        }

        // Directory: read tags file
        $tagsFile = "{$path}/{$this->config['tags_file']}";
        if (file_exists($tagsFile)) {
            $content = file_get_contents($tagsFile);
            $data = json_decode($content, true);
            if (is_array($data)) {
                if ($kind) {
                    $data = array_filter($data, fn($s) => strcasecmp($s['kind'] ?? '', $kind) === 0);
                    $data = array_values($data);
                }
                return $this->success(['path' => $path, 'symbols' => array_slice($data, 0, $this->config['max_results']), 'count' => count($data)]);
            }
        }

        return $this->error('No symbol index found. Run action=index first.');
    }

    // ── outline ───────────────────────────────────────────────

    private function outline(string $path): array
    {
        if (!is_file($path)) return $this->error("File not found: {$path}");

        $symbols = [];

        if ($this->config['use_ctags'] && $this->hasCTags()) {
            $cmd = "ctags --output-format=json --fields=+lnKS --sort=no -f - " . escapeshellarg($path) . " 2>/dev/null";
            $result = $this->shell($cmd);
            $symbols = $this->parseCtagsJson($result['stdout'] ?? '');
        }

        if (empty($symbols)) {
            $symbols = $this->regexSymbolsForFile($path);
        }

        // Group by kind
        $grouped = [];
        foreach ($symbols as $s) {
            $kind = $s['kind'] ?? 'unknown';
            $grouped[$kind][] = $s;
        }

        return $this->success([
            'file'    => $path,
            'outline' => $grouped,
            'symbols' => $symbols,
            'count'   => count($symbols),
        ]);
    }

    // ── ctags helpers ─────────────────────────────────────────

    private function hasCTags(): bool
    {
        static $has = null;
        if ($has === null) {
            $result = $this->shell('which ctags 2>/dev/null');
            $has = ($result['exit_code'] ?? 1) === 0 && !empty(trim($result['stdout'] ?? ''));
        }
        return $has;
    }

    private function parseCtagsJson(string $output): array
    {
        $symbols = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (!$line || $line[0] !== '{') continue;
            $tag = json_decode($line, true);
            if (!$tag) continue;
            $symbols[] = [
                'name'   => $tag['name'] ?? '',
                'kind'   => $tag['kind'] ?? 'unknown',
                'line'   => $tag['line'] ?? null,
                'file'   => $tag['path'] ?? null,
                'scope'  => $tag['scope'] ?? null,
                'signature' => $tag['signature'] ?? null,
            ];
        }
        return $symbols;
    }

    private function searchTags(string $tagsFile, string $symbol, ?string $kind): array
    {
        $results = [];
        $content = file_get_contents($tagsFile);

        // Try JSON format first
        $data = json_decode($content, true);
        if (is_array($data)) {
            foreach ($data as $entry) {
                if (($entry['name'] ?? '') === $symbol) {
                    if ($kind && strcasecmp($entry['kind'] ?? '', $kind) !== 0) continue;
                    $results[] = $entry;
                }
            }
            return $results;
        }

        // Traditional ctags format
        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, '!')) continue;
            $parts = explode("\t", $line);
            if (count($parts) < 3) continue;
            if ($parts[0] === $symbol) {
                $results[] = [
                    'name' => $parts[0],
                    'file' => $parts[1],
                    'pattern' => $parts[2] ?? null,
                    'kind' => $parts[3] ?? null,
                ];
            }
        }

        return $results;
    }

    // ── grep fallback ─────────────────────────────────────────

    private function grepDefinitions(string $symbol, string $dir, ?string $kind): array
    {
        $escaped = preg_quote($symbol, '/');
        $patterns = [
            'function' => "/(function|def|fn|func)\s+{$escaped}\s*[\(\<]/",
            'class'    => "/(class|struct|enum|interface|trait|type)\s+{$escaped}/",
            'method'   => "/(public|private|protected|static)?\s*(function|def|fn)\s+{$escaped}\s*[\(]/",
            'variable' => "/(const|let|var|val)\s+{$escaped}\s*[=:]/",
        ];

        $results = [];
        $kindsToSearch = $kind ? [$kind] : array_keys($patterns);

        foreach ($kindsToSearch as $k) {
            if (!isset($patterns[$k])) continue;
            $pattern = $patterns[$k];

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) continue;
                $ext = $file->getExtension();
                if (!in_array($ext, ['php', 'js', 'ts', 'tsx', 'py', 'go', 'rs', 'java', 'rb', 'swift', 'kt', 'c', 'cpp', 'h', 'cs', 'ex', 'dart', 'zig', 'lua'])) continue;

                $content = @file_get_contents($file->getPathname());
                if ($content === false) continue;

                $lines = explode("\n", $content);
                foreach ($lines as $i => $line) {
                    if (preg_match($pattern, $line)) {
                        $results[] = [
                            'name'    => $symbol,
                            'kind'    => $k,
                            'file'    => $file->getPathname(),
                            'line'    => $i + 1,
                            'content' => trim($line),
                        ];
                    }
                }

                if (count($results) >= $this->config['max_results']) break 2;
            }
        }

        return $results;
    }

    // ── regex symbol extraction ───────────────────────────────

    private function regexSymbolsForFile(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) return [];

        $symbols = [];
        $lines = explode("\n", $content);
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        foreach ($lines as $i => $line) {
            // Functions
            if (preg_match('/(?:function|def|fn|func)\s+(\w+)\s*[\(\<]/', $line, $m)) {
                $symbols[] = ['name' => $m[1], 'kind' => 'function', 'line' => $i + 1];
            }
            // Classes / structs / enums / interfaces / traits
            if (preg_match('/(?:class|struct|enum|interface|trait)\s+(\w+)/', $line, $m)) {
                $symbols[] = ['name' => $m[1], 'kind' => 'class', 'line' => $i + 1];
            }
            // Constants
            if (preg_match('/(?:const|define)\s*\(?\s*[\'"]?(\w+)/', $line, $m)) {
                $symbols[] = ['name' => $m[1], 'kind' => 'constant', 'line' => $i + 1];
            }
            // Type aliases (Go, Rust, TS)
            if (preg_match('/type\s+(\w+)\s/', $line, $m)) {
                $symbols[] = ['name' => $m[1], 'kind' => 'type', 'line' => $i + 1];
            }
        }

        return $symbols;
    }

    private function regexIndex(string $path): array
    {
        $symbols = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $codeExts = ['php', 'js', 'ts', 'tsx', 'py', 'go', 'rs', 'java', 'rb', 'swift', 'kt', 'c', 'cpp', 'h', 'cs', 'ex', 'dart', 'zig', 'lua'];

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            if (!in_array($file->getExtension(), $codeExts)) continue;
            // Skip vendor/node_modules
            $rel = str_replace($path . '/', '', $file->getPathname());
            if (preg_match('#^(vendor|node_modules|\.git|__pycache__|target|build|dist)/#', $rel)) continue;

            $fileSymbols = $this->regexSymbolsForFile($file->getPathname());
            foreach ($fileSymbols as &$s) {
                $s['file'] = $file->getPathname();
            }
            $symbols = array_merge($symbols, $fileSymbols);

            if (count($symbols) > 10000) break;
        }

        return $symbols;
    }

    // ── shell helper ──────────────────────────────────────────

    private function shell(string $command, ?string $cwd = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd ?? getcwd());
        if (!is_resource($process)) return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Failed to start process'];
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 10_485_760);
        $stderr = stream_get_contents($pipes[2], 10_485_760);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
