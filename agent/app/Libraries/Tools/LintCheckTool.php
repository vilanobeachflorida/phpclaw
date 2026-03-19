<?php

namespace App\Libraries\Tools;

/**
 * Universal static analysis and linting tool — language-agnostic.
 *
 * Detects installed linters from project config files, runs them,
 * and normalises output into structured diagnostics.
 *
 * Actions:
 *   detect – identify linters / formatters in the project
 *   run    – execute linter and return structured diagnostics
 *   fix    – auto-fix where supported
 */
class LintCheckTool extends BaseTool
{
    protected string $name = 'lint_check';
    protected string $description = 'Detect and run linters/formatters for any language, returning structured diagnostics';

    protected function getDefaultConfig(): array
    {
        return ['enabled' => true, 'timeout' => 60];
    }

    public function getInputSchema(): array
    {
        return [
            'action'    => ['type' => 'string', 'required' => true, 'enum' => ['detect', 'run', 'fix']],
            'path'      => ['type' => 'string', 'required' => false, 'description' => 'Project directory (defaults to cwd)'],
            'linter'    => ['type' => 'string', 'required' => false, 'description' => 'Linter name to run (overrides auto-detect)'],
            'target'    => ['type' => 'string', 'required' => false, 'description' => 'Specific file or directory to lint'],
            'extra_args'=> ['type' => 'string', 'required' => false, 'description' => 'Additional CLI args'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        $path = $args['path'] ?? getcwd();

        switch ($args['action']) {
            case 'detect':
                return $this->detect($path);
            case 'run':
                return $this->runLint($path, $args, false);
            case 'fix':
                return $this->runLint($path, $args, true);
            default:
                return $this->error("Unknown action: {$args['action']}");
        }
    }

    private function detect(string $path): array
    {
        $linters = [];
        $checks = $this->getLinterDefinitions();

        foreach ($checks as $def) {
            foreach ($def['markers'] as $marker) {
                if (file_exists("{$path}/{$marker}")) {
                    $linters[] = [
                        'name'        => $def['name'],
                        'language'    => $def['language'],
                        'run_command' => $def['run'],
                        'fix_command' => $def['fix'] ?? null,
                        'json_flag'   => $def['json_flag'] ?? null,
                        'marker'      => $marker,
                    ];
                    break;
                }
            }
        }

        return $this->success(['linters' => $linters, 'count' => count($linters)]);
    }

    private function runLint(string $path, array $args, bool $fix): array
    {
        $linterName = $args['linter'] ?? null;
        $target     = $args['target'] ?? '.';
        $extra      = $args['extra_args'] ?? '';

        if (!$linterName) {
            $detect = $this->detect($path);
            $linters = $detect['data']['linters'] ?? [];
            if (empty($linters)) return $this->error('No linter detected. Use linter param to specify one.');
            $def = $linters[0];
            $linterName = $def['name'];
        } else {
            $def = $this->findLinterDef($linterName);
            if (!$def) return $this->error("Unknown linter: {$linterName}");
        }

        $command = $fix ? ($def['fix_command'] ?? $def['run_command']) : $def['run_command'];

        // Append target if not "."
        if ($target && $target !== '.') {
            $command .= ' ' . escapeshellarg($target);
        }

        // Try to get JSON output
        if (!$fix && isset($def['json_flag'])) {
            // JSON flag is already in run_command for most linters
        }

        if ($extra) $command .= ' ' . $extra;

        // Execute
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $path);
        if (!is_resource($process)) return $this->error("Failed to start linter: {$command}");

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Parse output
        $diagnostics = $this->parseDiagnostics($stdout, $stderr, $linterName);

        return $this->success([
            'linter'      => $linterName,
            'command'     => $command,
            'mode'        => $fix ? 'fix' : 'check',
            'exit_code'   => $exitCode,
            'issue_count' => count($diagnostics),
            'diagnostics' => array_slice($diagnostics, 0, 200),
            'stdout'      => mb_strlen($stdout) > 8192 ? mb_substr($stdout, 0, 8192) . "\n... (truncated)" : $stdout,
            'stderr'      => mb_strlen($stderr) > 4096 ? mb_substr($stderr, 0, 4096) . "\n... (truncated)" : $stderr,
        ]);
    }

    private function parseDiagnostics(string $stdout, string $stderr, string $linterName): array
    {
        // Try JSON first
        $json = json_decode($stdout, true);
        if (is_array($json)) {
            return $this->normalizeJsonDiagnostics($json, $linterName);
        }

        // Fallback: regex patterns for common formats
        $diags = [];
        $combined = $stdout . "\n" . $stderr;

        // file:line:col: message
        if (preg_match_all('/^(.+?):(\d+):(\d+):\s*(.+)$/m', $combined, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $diags[] = [
                    'file'     => $m[1],
                    'line'     => (int)$m[2],
                    'column'   => (int)$m[3],
                    'message'  => trim($m[4]),
                    'severity' => $this->guessSeverity($m[4]),
                ];
            }
        }
        // file:line: message (no column)
        elseif (preg_match_all('/^(.+?):(\d+):\s*(.+)$/m', $combined, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $diags[] = [
                    'file'     => $m[1],
                    'line'     => (int)$m[2],
                    'column'   => null,
                    'message'  => trim($m[3]),
                    'severity' => $this->guessSeverity($m[3]),
                ];
            }
        }

        return $diags;
    }

    private function normalizeJsonDiagnostics(array $json, string $linterName): array
    {
        $diags = [];

        switch ($linterName) {
            case 'ESLint':
                // ESLint JSON format: [{filePath, messages: [{line, column, message, severity, ruleId}]}]
                foreach ($json as $file) {
                    foreach ($file['messages'] ?? [] as $msg) {
                        $diags[] = [
                            'file'     => $file['filePath'] ?? null,
                            'line'     => $msg['line'] ?? null,
                            'column'   => $msg['column'] ?? null,
                            'message'  => $msg['message'] ?? '',
                            'severity' => ($msg['severity'] ?? 1) >= 2 ? 'error' : 'warning',
                            'rule'     => $msg['ruleId'] ?? null,
                        ];
                    }
                }
                break;

            case 'PHPStan':
                // PHPStan JSON: {totals: {file_errors, errors}, files: {path: {messages: [{message, line}]}}}
                foreach ($json['files'] ?? [] as $file => $data) {
                    foreach ($data['messages'] ?? [] as $msg) {
                        $diags[] = [
                            'file'     => $file,
                            'line'     => $msg['line'] ?? null,
                            'column'   => null,
                            'message'  => $msg['message'] ?? '',
                            'severity' => 'error',
                        ];
                    }
                }
                break;

            case 'Ruff':
                // Ruff JSON: [{code, message, filename, location: {row, column}}]
                foreach ($json as $item) {
                    $diags[] = [
                        'file'     => $item['filename'] ?? null,
                        'line'     => $item['location']['row'] ?? null,
                        'column'   => $item['location']['column'] ?? null,
                        'message'  => $item['message'] ?? '',
                        'severity' => 'warning',
                        'rule'     => $item['code'] ?? null,
                    ];
                }
                break;

            case 'Biome':
                // Biome JSON: {diagnostics: [{message, location: {path, span: {start, end}}}]}
                foreach ($json['diagnostics'] ?? [] as $d) {
                    $diags[] = [
                        'file'     => $d['location']['path'] ?? null,
                        'line'     => null,
                        'column'   => null,
                        'message'  => $d['message'] ?? '',
                        'severity' => $d['severity'] ?? 'warning',
                    ];
                }
                break;

            default:
                // Generic: try to use as-is if it's a flat array of objects
                foreach ($json as $item) {
                    if (is_array($item) && isset($item['message'])) {
                        $diags[] = [
                            'file'     => $item['file'] ?? $item['filename'] ?? $item['path'] ?? null,
                            'line'     => $item['line'] ?? $item['row'] ?? null,
                            'column'   => $item['column'] ?? $item['col'] ?? null,
                            'message'  => $item['message'] ?? '',
                            'severity' => $item['severity'] ?? $item['level'] ?? 'warning',
                        ];
                    }
                }
                break;
        }

        return $diags;
    }

    private function guessSeverity(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'error') || str_contains($lower, 'fatal')) return 'error';
        if (str_contains($lower, 'warning') || str_contains($lower, 'warn')) return 'warning';
        if (str_contains($lower, 'info') || str_contains($lower, 'note')) return 'info';
        return 'warning';
    }

    private function getLinterDefinitions(): array
    {
        return [
            ['name' => 'PHPStan', 'language' => 'PHP', 'markers' => ['phpstan.neon', 'phpstan.neon.dist'], 'run' => 'vendor/bin/phpstan analyse --error-format=json', 'fix' => null, 'json_flag' => '--error-format=json'],
            ['name' => 'PHP-CS-Fixer', 'language' => 'PHP', 'markers' => ['.php-cs-fixer.php', '.php-cs-fixer.dist.php'], 'run' => 'vendor/bin/php-cs-fixer fix --dry-run --format=json', 'fix' => 'vendor/bin/php-cs-fixer fix', 'json_flag' => '--format=json'],
            ['name' => 'Psalm', 'language' => 'PHP', 'markers' => ['psalm.xml', 'psalm.xml.dist'], 'run' => 'vendor/bin/psalm --output-format=json', 'fix' => null, 'json_flag' => '--output-format=json'],
            ['name' => 'ESLint', 'language' => 'JavaScript', 'markers' => ['.eslintrc.js', '.eslintrc.json', '.eslintrc.cjs', 'eslint.config.js', 'eslint.config.mjs'], 'run' => 'npx eslint --format json .', 'fix' => 'npx eslint --fix .', 'json_flag' => '--format json'],
            ['name' => 'Prettier', 'language' => 'JavaScript', 'markers' => ['.prettierrc', '.prettierrc.json', 'prettier.config.js'], 'run' => 'npx prettier --check .', 'fix' => 'npx prettier --write .'],
            ['name' => 'Biome', 'language' => 'JavaScript', 'markers' => ['biome.json'], 'run' => 'npx biome check --reporter=json .', 'fix' => 'npx biome check --apply .', 'json_flag' => '--reporter=json'],
            ['name' => 'Ruff', 'language' => 'Python', 'markers' => ['ruff.toml'], 'run' => 'ruff check --output-format json .', 'fix' => 'ruff check --fix .', 'json_flag' => '--output-format json'],
            ['name' => 'Flake8', 'language' => 'Python', 'markers' => ['.flake8'], 'run' => 'flake8 --format json .', 'fix' => null, 'json_flag' => '--format json'],
            ['name' => 'golangci-lint', 'language' => 'Go', 'markers' => ['.golangci.yml', '.golangci.yaml'], 'run' => 'golangci-lint run --out-format json', 'fix' => 'golangci-lint run --fix', 'json_flag' => '--out-format json'],
            ['name' => 'Clippy', 'language' => 'Rust', 'markers' => ['clippy.toml'], 'run' => 'cargo clippy --message-format json 2>&1', 'fix' => 'cargo clippy --fix --allow-dirty', 'json_flag' => '--message-format json'],
            ['name' => 'RuboCop', 'language' => 'Ruby', 'markers' => ['.rubocop.yml'], 'run' => 'bundle exec rubocop --format json', 'fix' => 'bundle exec rubocop --autocorrect', 'json_flag' => '--format json'],
            ['name' => 'Dart Analyze', 'language' => 'Dart', 'markers' => ['analysis_options.yaml'], 'run' => 'dart analyze', 'fix' => 'dart fix --apply'],
        ];
    }

    private function findLinterDef(string $name): ?array
    {
        foreach ($this->getLinterDefinitions() as $def) {
            if (strcasecmp($def['name'], $name) === 0) return $def;
        }
        // Allow short names
        $map = [
            'phpstan' => 'PHPStan', 'eslint' => 'ESLint', 'prettier' => 'Prettier',
            'ruff' => 'Ruff', 'flake8' => 'Flake8', 'clippy' => 'Clippy',
            'rubocop' => 'RuboCop', 'biome' => 'Biome', 'psalm' => 'Psalm',
            'php-cs-fixer' => 'PHP-CS-Fixer', 'golangci-lint' => 'golangci-lint',
        ];
        $canonical = $map[strtolower($name)] ?? null;
        if ($canonical) return $this->findLinterDef($canonical);
        return null;
    }
}
