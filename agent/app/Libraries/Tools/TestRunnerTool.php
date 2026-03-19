<?php

namespace App\Libraries\Tools;

/**
 * Universal test runner — language-agnostic.
 *
 * Detects the project's test framework, executes tests, and returns
 * structured results (pass/fail/error/skip per test) instead of raw CLI output.
 *
 * Actions:
 *   detect  – identify test framework(s) in the project
 *   run     – execute tests (all, file, method/function, suite)
 *   parse   – parse raw test output into structured results
 */
class TestRunnerTool extends BaseTool
{
    protected string $name = 'test_runner';
    protected string $description = 'Detect and run tests for any language, returning structured pass/fail results';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 120,
            'junit_output' => true,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'action'    => ['type' => 'string', 'required' => true, 'enum' => ['detect', 'run', 'parse']],
            'path'      => ['type' => 'string', 'required' => false, 'description' => 'Project directory (defaults to cwd)'],
            'scope'     => ['type' => 'string', 'required' => false, 'enum' => ['all', 'file', 'method', 'suite'], 'default' => 'all'],
            'target'    => ['type' => 'string', 'required' => false, 'description' => 'File path, method name, or suite name'],
            'framework' => ['type' => 'string', 'required' => false, 'description' => 'Override framework detection'],
            'extra_args'=> ['type' => 'string', 'required' => false, 'description' => 'Additional CLI args to pass to test runner'],
            'raw_output'=> ['type' => 'string', 'required' => false, 'description' => 'Raw test output to parse (action=parse)'],
            'format'    => ['type' => 'string', 'required' => false, 'description' => 'Output format of raw_output (junit, tap, json)'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        $path = $args['path'] ?? getcwd();

        switch ($args['action']) {
            case 'detect':
                return $this->detectFramework($path);
            case 'run':
                return $this->runTests($path, $args);
            case 'parse':
                if ($err = $this->requireArgs($args, ['raw_output'])) return $err;
                return $this->parseOutput($args['raw_output'], $args['format'] ?? 'auto');
            default:
                return $this->error("Unknown action: {$args['action']}");
        }
    }

    private function detectFramework(string $path): array
    {
        $frameworks = [];
        $checks = [
            ['files' => ['phpunit.xml', 'phpunit.xml.dist'], 'name' => 'phpunit', 'command' => 'vendor/bin/phpunit', 'junit_flag' => '--log-junit'],
            ['files' => ['jest.config.js', 'jest.config.ts', 'jest.config.mjs'], 'name' => 'jest', 'command' => 'npx jest', 'junit_flag' => '--reporters=default --reporters=jest-junit'],
            ['files' => ['vitest.config.ts', 'vitest.config.js'], 'name' => 'vitest', 'command' => 'npx vitest run', 'junit_flag' => '--reporter=junit'],
            ['files' => ['pytest.ini', 'conftest.py'], 'name' => 'pytest', 'command' => 'python -m pytest', 'junit_flag' => '--junitxml='],
            ['files' => ['Cargo.toml'], 'name' => 'cargo_test', 'command' => 'cargo test', 'junit_flag' => null],
            ['files' => ['go.mod'], 'name' => 'go_test', 'command' => 'go test ./...', 'junit_flag' => null],
            ['files' => ['.rspec'], 'name' => 'rspec', 'command' => 'bundle exec rspec', 'junit_flag' => '--format RspecJunitFormatter --out'],
            ['files' => ['build.gradle', 'build.gradle.kts'], 'name' => 'gradle', 'command' => './gradlew test', 'junit_flag' => null],
            ['files' => ['pom.xml'], 'name' => 'maven', 'command' => 'mvn test', 'junit_flag' => null],
            ['files' => ['pubspec.yaml'], 'name' => 'flutter_test', 'command' => 'flutter test', 'junit_flag' => '--machine'],
            ['files' => ['mix.exs'], 'name' => 'exunit', 'command' => 'mix test', 'junit_flag' => null],
            ['files' => ['Makefile'], 'name' => 'make', 'command' => 'make test', 'junit_flag' => null, 'check_target' => true],
        ];

        foreach ($checks as $c) {
            foreach ($c['files'] as $f) {
                if (file_exists("{$path}/{$f}")) {
                    if (isset($c['check_target']) && $c['check_target']) {
                        $content = @file_get_contents("{$path}/{$f}");
                        if ($content === false || !preg_match('/^test\s*:/m', $content)) continue;
                    }
                    if ($c['name'] === 'pytest' && $f === 'conftest.py' && !file_exists("{$path}/pytest.ini")) {
                        // conftest.py alone is okay
                    }
                    $frameworks[] = [
                        'name'       => $c['name'],
                        'command'    => $c['command'],
                        'junit_flag' => $c['junit_flag'],
                        'marker'     => $f,
                    ];
                    break;
                }
            }
        }

        // Check pyproject.toml for pytest
        if (file_exists("{$path}/pyproject.toml")) {
            $content = @file_get_contents("{$path}/pyproject.toml");
            if ($content && stripos($content, 'pytest') !== false) {
                $already = array_filter($frameworks, fn($f) => $f['name'] === 'pytest');
                if (empty($already)) {
                    $frameworks[] = ['name' => 'pytest', 'command' => 'python -m pytest', 'junit_flag' => '--junitxml=', 'marker' => 'pyproject.toml'];
                }
            }
        }

        return $this->success(['frameworks' => $frameworks, 'count' => count($frameworks)]);
    }

    private function runTests(string $path, array $args): array
    {
        // Detect or use provided framework
        $fwName = $args['framework'] ?? null;
        if (!$fwName) {
            $detect = $this->detectFramework($path);
            $frameworks = $detect['data']['frameworks'] ?? [];
            if (empty($frameworks)) return $this->error('No test framework detected. Use framework param to specify one.');
            $fw = $frameworks[0];
        } else {
            $fw = ['name' => $fwName, 'command' => $this->resolveCommand($fwName)];
        }

        $command = $fw['command'];
        $scope   = $args['scope'] ?? 'all';
        $target  = $args['target'] ?? '';
        $extra   = $args['extra_args'] ?? '';

        // Build command based on scope
        switch ($fw['name']) {
            case 'phpunit':
                if ($scope === 'file' && $target) $command .= ' ' . escapeshellarg($target);
                elseif ($scope === 'method' && $target) $command .= ' --filter ' . escapeshellarg($target);
                elseif ($scope === 'suite' && $target) $command .= ' --testsuite ' . escapeshellarg($target);
                break;

            case 'jest':
            case 'vitest':
                if ($scope === 'file' && $target) $command .= ' ' . escapeshellarg($target);
                elseif ($scope === 'method' && $target) $command .= ' -t ' . escapeshellarg($target);
                break;

            case 'pytest':
                if ($scope === 'file' && $target) $command .= ' ' . escapeshellarg($target);
                elseif ($scope === 'method' && $target) $command .= ' -k ' . escapeshellarg($target);
                break;

            case 'cargo_test':
                if ($scope === 'method' && $target) $command .= ' ' . escapeshellarg($target);
                break;

            case 'go_test':
                if ($scope === 'file' && $target) $command = 'go test ' . escapeshellarg($target);
                elseif ($scope === 'method' && $target) $command .= ' -run ' . escapeshellarg($target);
                break;

            case 'rspec':
                if ($scope === 'file' && $target) $command .= ' ' . escapeshellarg($target);
                break;
        }

        // Add JUnit output for structured parsing
        $junitFile = null;
        if ($this->config['junit_output'] && isset($fw['junit_flag']) && $fw['junit_flag']) {
            $junitFile = sys_get_temp_dir() . '/phpclaw_test_' . getmypid() . '.xml';
            $flag = $fw['junit_flag'];
            if (str_ends_with($flag, '=')) {
                $command .= ' ' . $flag . escapeshellarg($junitFile);
            } else {
                $command .= ' ' . $flag . ' ' . escapeshellarg($junitFile);
            }
        }

        if ($extra) $command .= ' ' . $extra;

        // Execute
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $path);
        if (!is_resource($process)) return $this->error("Failed to start test runner: {$command}");

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 10_485_760);
        $stderr = stream_get_contents($pipes[2], 10_485_760);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Parse JUnit XML if available
        $results = null;
        if ($junitFile && file_exists($junitFile)) {
            $junitXml = file_get_contents($junitFile);
            $results = $this->parseJunit($junitXml);
            @unlink($junitFile);
        }

        // Fallback: parse stdout for summary
        if (!$results) {
            $results = $this->parseRawOutput($stdout . "\n" . $stderr, $fw['name']);
        }

        return $this->success([
            'framework' => $fw['name'],
            'command'   => $command,
            'exit_code' => $exitCode,
            'passed'    => $results['passed'] ?? 0,
            'failed'    => $results['failed'] ?? 0,
            'errors'    => $results['errors'] ?? 0,
            'skipped'   => $results['skipped'] ?? 0,
            'total'     => $results['total'] ?? 0,
            'duration'  => $results['duration'] ?? null,
            'tests'     => $results['tests'] ?? [],
            'stdout'    => mb_strlen($stdout) > 8192 ? mb_substr($stdout, 0, 8192) . "\n... (truncated)" : $stdout,
            'stderr'    => mb_strlen($stderr) > 4096 ? mb_substr($stderr, 0, 4096) . "\n... (truncated)" : $stderr,
        ]);
    }

    private function parseJunit(string $xml): ?array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) return null;

        $tests   = [];
        $passed  = 0;
        $failed  = 0;
        $errors  = 0;
        $skipped = 0;

        $suites = $doc->testsuite ?? $doc;
        foreach ($suites as $suite) {
            $this->parseSuite($suite, $tests, $passed, $failed, $errors, $skipped);
        }

        $total = $passed + $failed + $errors + $skipped;

        return [
            'tests'    => $tests,
            'passed'   => $passed,
            'failed'   => $failed,
            'errors'   => $errors,
            'skipped'  => $skipped,
            'total'    => $total,
            'duration' => (string)($doc['time'] ?? $suites['time'] ?? null),
        ];
    }

    private function parseSuite(\SimpleXMLElement $suite, array &$tests, int &$passed, int &$failed, int &$errors, int &$skipped): void
    {
        foreach ($suite->testcase ?? [] as $tc) {
            $name  = (string)($tc['name'] ?? 'unknown');
            $class = (string)($tc['classname'] ?? '');
            $time  = (string)($tc['time'] ?? '');
            $file  = (string)($tc['file'] ?? '');
            $line  = (string)($tc['line'] ?? '');

            if (isset($tc->failure)) {
                $failed++;
                $status = 'failed';
                $message = (string)$tc->failure;
            } elseif (isset($tc->error)) {
                $errors++;
                $status = 'error';
                $message = (string)$tc->error;
            } elseif (isset($tc->skipped)) {
                $skipped++;
                $status = 'skipped';
                $message = (string)($tc->skipped ?? '');
            } else {
                $passed++;
                $status = 'passed';
                $message = '';
            }

            $tests[] = [
                'name'    => $class ? "{$class}::{$name}" : $name,
                'status'  => $status,
                'message' => trim($message) ?: null,
                'file'    => $file ?: null,
                'line'    => $line ? (int)$line : null,
                'duration'=> $time ?: null,
            ];
        }

        // Recurse into nested suites
        foreach ($suite->testsuite ?? [] as $child) {
            $this->parseSuite($child, $tests, $passed, $failed, $errors, $skipped);
        }
    }

    private function parseRawOutput(string $output, string $framework): array
    {
        $passed = 0; $failed = 0; $errors = 0; $skipped = 0; $total = 0;

        switch ($framework) {
            case 'phpunit':
                // "Tests: 42, Assertions: 100, Failures: 2, Errors: 1, Skipped: 3."
                if (preg_match('/Tests:\s*(\d+)/', $output, $m)) $total = (int)$m[1];
                if (preg_match('/Failures:\s*(\d+)/', $output, $m)) $failed = (int)$m[1];
                if (preg_match('/Errors:\s*(\d+)/', $output, $m)) $errors = (int)$m[1];
                if (preg_match('/Skipped:\s*(\d+)/', $output, $m)) $skipped = (int)$m[1];
                $passed = $total - $failed - $errors - $skipped;
                break;

            case 'jest':
            case 'vitest':
                // "Tests:  2 failed, 10 passed, 12 total"
                if (preg_match('/(\d+)\s+passed/', $output, $m)) $passed = (int)$m[1];
                if (preg_match('/(\d+)\s+failed/', $output, $m)) $failed = (int)$m[1];
                if (preg_match('/(\d+)\s+skipped/', $output, $m)) $skipped = (int)$m[1];
                if (preg_match('/(\d+)\s+total/', $output, $m)) $total = (int)$m[1];
                break;

            case 'pytest':
                // "5 passed, 2 failed, 1 error in 3.2s"
                if (preg_match('/(\d+)\s+passed/', $output, $m)) $passed = (int)$m[1];
                if (preg_match('/(\d+)\s+failed/', $output, $m)) $failed = (int)$m[1];
                if (preg_match('/(\d+)\s+error/', $output, $m)) $errors = (int)$m[1];
                if (preg_match('/(\d+)\s+skipped/', $output, $m)) $skipped = (int)$m[1];
                $total = $passed + $failed + $errors + $skipped;
                break;

            case 'cargo_test':
                // "test result: ok. 10 passed; 0 failed; 0 ignored;"
                if (preg_match('/(\d+)\s+passed/', $output, $m)) $passed = (int)$m[1];
                if (preg_match('/(\d+)\s+failed/', $output, $m)) $failed = (int)$m[1];
                if (preg_match('/(\d+)\s+ignored/', $output, $m)) $skipped = (int)$m[1];
                $total = $passed + $failed + $skipped;
                break;

            case 'go_test':
                // Count "--- PASS:" and "--- FAIL:"
                $passed = preg_match_all('/--- PASS:/', $output);
                $failed = preg_match_all('/--- FAIL:/', $output);
                $skipped = preg_match_all('/--- SKIP:/', $output);
                $total = $passed + $failed + $skipped;
                break;

            default:
                // Generic: look for common patterns
                if (preg_match('/(\d+)\s+pass/', $output, $m)) $passed = (int)$m[1];
                if (preg_match('/(\d+)\s+fail/', $output, $m)) $failed = (int)$m[1];
                $total = $passed + $failed;
                break;
        }

        return [
            'tests'   => [],
            'passed'  => $passed,
            'failed'  => $failed,
            'errors'  => $errors,
            'skipped' => $skipped,
            'total'   => $total,
        ];
    }

    private function parseOutput(string $output, string $format): array
    {
        switch ($format) {
            case 'junit':
                $result = $this->parseJunit($output);
                return $result ? $this->success($result) : $this->error('Failed to parse JUnit XML');
            case 'json':
                $data = json_decode($output, true);
                return $data ? $this->success($data) : $this->error('Failed to parse JSON output');
            default:
                // Try JUnit first, then JSON
                $result = $this->parseJunit($output);
                if ($result) return $this->success($result);
                $data = json_decode($output, true);
                if ($data) return $this->success($data);
                return $this->success(['raw' => $output, 'note' => 'Could not auto-detect format']);
        }
    }

    private function resolveCommand(string $name): string
    {
        $map = [
            'phpunit' => 'vendor/bin/phpunit', 'jest' => 'npx jest', 'vitest' => 'npx vitest run',
            'pytest' => 'python -m pytest', 'cargo_test' => 'cargo test', 'go_test' => 'go test ./...',
            'rspec' => 'bundle exec rspec', 'gradle' => './gradlew test', 'maven' => 'mvn test',
            'flutter_test' => 'flutter test', 'exunit' => 'mix test', 'make' => 'make test',
        ];
        return $map[$name] ?? $name;
    }
}
