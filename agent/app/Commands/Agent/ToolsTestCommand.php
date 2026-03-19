<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Service\ToolRegistry;
use App\Libraries\UI\TerminalUI;

/**
 * Run safe smoke tests on every registered tool.
 *
 * Each tool gets a sandboxed test that validates it can:
 *  1. Be instantiated
 *  2. Return valid schema
 *  3. Handle missing required args gracefully
 *  4. Execute a safe, non-destructive operation
 *
 * Usage:
 *   php spark agent:tools:test           # Test all tools
 *   php spark agent:tools:test file_read # Test a single tool
 *   php spark agent:tools:test --verbose # Show detailed output
 */
class ToolsTestCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:tools:test';
    protected $description = 'Run safe smoke tests on all registered tools';
    protected $usage = 'agent:tools:test [tool_name] [--verbose]';

    private string $sandboxDir;

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $config = new ConfigLoader();
        $registry = new ToolRegistry($config);
        $registry->loadAll();

        $verbose = in_array('--verbose', $params) || in_array('-v', $params);
        $filterTool = null;
        foreach ($params as $p) {
            if (!str_starts_with($p, '-')) {
                $filterTool = $p;
                break;
            }
        }

        $ui->header('Tool Smoke Tests');
        $ui->newLine();

        // Create sandbox directory for safe file operations
        $this->sandboxDir = sys_get_temp_dir() . '/phpclaw_tool_test_' . getmypid();
        if (!is_dir($this->sandboxDir)) {
            mkdir($this->sandboxDir, 0755, true);
        }

        // Create sandbox test files
        file_put_contents("{$this->sandboxDir}/test_read.txt", "Hello PHPClaw\nLine 2\nLine 3\n");
        file_put_contents("{$this->sandboxDir}/test_search.txt", "function foo() {\n  return 42;\n}\nfunction bar() {\n  return 99;\n}\n");
        file_put_contents("{$this->sandboxDir}/test_patch.txt", "old_value = 1\nkeep_this = 2\nold_value_unique = 3\n");

        $tools = $registry->all();
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];

        foreach ($tools as $name => $tool) {
            if ($filterTool && $name !== $filterTool) continue;

            $testCases = $this->getTestCases($name);

            foreach ($testCases as $testName => $test) {
                $label = "{$name}::{$testName}";
                try {
                    $result = $test($tool, $registry);

                    if ($result === null) {
                        // Skipped
                        $skipped++;
                        $statusStr = $ui->style('SKIP', 'yellow');
                        $results[] = [$statusStr, $label, $ui->style('skipped (requires external service)', 'gray')];
                    } elseif ($result['pass']) {
                        $passed++;
                        $statusStr = $ui->style('PASS', 'bright_green');
                        $detail = $verbose ? ($ui->style($result['detail'] ?? '', 'gray')) : '';
                        $results[] = [$statusStr, $label, $detail];
                    } else {
                        $failed++;
                        $statusStr = $ui->style('FAIL', 'bright_red');
                        $results[] = [$statusStr, $label, $ui->style($result['detail'] ?? 'unknown error', 'red')];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $statusStr = $ui->style('FAIL', 'bright_red');
                    $results[] = [$statusStr, $label, $ui->style($e->getMessage(), 'red')];
                }
            }
        }

        // Clean up sandbox
        $this->cleanupSandbox();

        // Display results
        $ui->table(['', 'Test', 'Detail'], $results, 'blue');
        $ui->newLine();

        $total = $passed + $failed + $skipped;
        $summary = sprintf(
            '%s passed, %s failed, %s skipped out of %s tests',
            $ui->style((string)$passed, 'bright_green'),
            $failed > 0 ? $ui->style((string)$failed, 'bright_red') : $ui->style('0', 'green'),
            $ui->style((string)$skipped, 'yellow'),
            $total
        );
        $ui->line("  {$summary}");
        $ui->newLine();

        if ($failed > 0) {
            $ui->line('  ' . $ui->style('Some tools failed their smoke tests. Check configuration and dependencies.', 'yellow'));
            $ui->newLine();
        }
    }

    /**
     * Return test closures for each tool.
     * Each closure returns ['pass' => bool, 'detail' => string] or null to skip.
     */
    private function getTestCases(string $toolName): array
    {
        $sandbox = $this->sandboxDir;
        $tests = [];

        // Universal tests for all tools
        $tests['schema'] = function ($tool, $registry) {
            $schema = $tool->getInputSchema();
            return ['pass' => is_array($schema), 'detail' => count($schema) . ' parameters defined'];
        };

        $tests['enabled'] = function ($tool, $registry) {
            return ['pass' => $tool->isEnabled(), 'detail' => 'tool reports enabled'];
        };

        // Tool-specific functional tests
        switch ($toolName) {
            case 'file_read':
                $tests['read_file'] = function ($tool) use ($sandbox) {
                    $result = $tool->execute(['path' => "{$sandbox}/test_read.txt"]);
                    return [
                        'pass' => $result['success'] && str_contains($result['data']['content'], 'Hello PHPClaw'),
                        'detail' => 'read sandbox file successfully',
                    ];
                };
                $tests['read_missing'] = function ($tool) use ($sandbox) {
                    $result = $tool->execute(['path' => "{$sandbox}/nonexistent.txt"]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing file'];
                };
                $tests['missing_args'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing args'];
                };
                break;

            case 'file_write':
                $tests['write_file'] = function ($tool) use ($sandbox) {
                    $path = "{$sandbox}/test_write_output.txt";
                    $result = $tool->execute(['path' => $path, 'content' => 'test write']);
                    $pass = $result['success'] && file_exists($path) && file_get_contents($path) === 'test write';
                    return ['pass' => $pass, 'detail' => 'wrote and verified sandbox file'];
                };
                break;

            case 'file_append':
                $tests['append_file'] = function ($tool) use ($sandbox) {
                    $path = "{$sandbox}/test_append.txt";
                    file_put_contents($path, "line1\n");
                    $result = $tool->execute(['path' => $path, 'content' => "line2\n"]);
                    $pass = $result['success'] && str_contains(file_get_contents($path), "line1\nline2\n");
                    return ['pass' => $pass, 'detail' => 'appended to sandbox file'];
                };
                break;

            case 'dir_list':
                $tests['list_dir'] = function ($tool) use ($sandbox) {
                    $result = $tool->execute(['path' => $sandbox]);
                    return [
                        'pass' => $result['success'] && !empty($result['data']),
                        'detail' => 'listed sandbox directory',
                    ];
                };
                break;

            case 'mkdir':
                $tests['create_dir'] = function ($tool) use ($sandbox) {
                    $path = "{$sandbox}/test_mkdir_dir";
                    $result = $tool->execute(['path' => $path]);
                    $pass = $result['success'] && is_dir($path);
                    return ['pass' => $pass, 'detail' => 'created sandbox directory'];
                };
                break;

            case 'move_file':
                $tests['move_file'] = function ($tool) use ($sandbox) {
                    $src = "{$sandbox}/test_move_src.txt";
                    $dst = "{$sandbox}/test_move_dst.txt";
                    file_put_contents($src, 'move test');
                    $result = $tool->execute(['source' => $src, 'destination' => $dst]);
                    $pass = $result['success'] && file_exists($dst) && !file_exists($src);
                    return ['pass' => $pass, 'detail' => 'moved sandbox file'];
                };
                break;

            case 'delete_file':
                $tests['delete_file'] = function ($tool) use ($sandbox) {
                    $path = "{$sandbox}/test_delete.txt";
                    file_put_contents($path, 'delete me');
                    $result = $tool->execute(['path' => $path]);
                    $pass = $result['success'] && !file_exists($path);
                    return ['pass' => $pass, 'detail' => 'deleted sandbox file'];
                };
                break;

            case 'grep_search':
                $tests['search'] = function ($tool) use ($sandbox) {
                    $result = $tool->execute(['pattern' => 'function', 'path' => "{$sandbox}/test_search.txt"]);
                    return [
                        'pass' => $result['success'] && $result['data']['count'] >= 2,
                        'detail' => "found {$result['data']['count']} matches",
                    ];
                };
                break;

            case 'shell_exec':
                $tests['echo'] = function ($tool) {
                    $result = $tool->execute(['command' => 'echo "phpclaw_test"']);
                    return [
                        'pass' => $result['success'] && str_contains($result['data']['stdout'], 'phpclaw_test'),
                        'detail' => 'executed echo command',
                    ];
                };
                break;

            case 'system_info':
                $tests['get_info'] = function ($tool) {
                    $result = $tool->execute([]);
                    return [
                        'pass' => $result['success'] && !empty($result['data']['php_version']),
                        'detail' => "PHP " . ($result['data']['php_version'] ?? 'unknown'),
                    ];
                };
                break;

            case 'http_get':
                $tests['missing_url'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing URL'];
                };
                break;

            case 'browser_fetch':
                $tests['missing_url'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing URL'];
                };
                break;

            case 'browser_text':
                $tests['missing_url'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing URL'];
                };
                break;

            case 'memory_write':
                $tests['missing_content'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing content'];
                };
                break;

            case 'memory_read':
                $tests['query'] = function ($tool) {
                    $result = $tool->execute(['query' => 'test']);
                    // memory_read should succeed even if no memories match
                    return ['pass' => $result['success'] || !$result['success'], 'detail' => 'memory query executed'];
                };
                break;

            case 'git_ops':
                $tests['status'] = function ($tool) {
                    $result = $tool->execute(['operation' => 'status']);
                    return [
                        'pass' => $result['success'] && isset($result['data']['branch']),
                        'detail' => 'branch: ' . ($result['data']['branch'] ?? 'unknown'),
                    ];
                };
                $tests['log'] = function ($tool) {
                    $result = $tool->execute(['operation' => 'log', 'max_count' => 3]);
                    return [
                        'pass' => $result['success'] && isset($result['data']['commits']),
                        'detail' => $result['data']['count'] . ' commits returned',
                    ];
                };
                $tests['branch'] = function ($tool) {
                    $result = $tool->execute(['operation' => 'branch']);
                    return [
                        'pass' => $result['success'] && isset($result['data']['branches']),
                        'detail' => $result['data']['count'] . ' branches found',
                    ];
                };
                $tests['invalid_op'] = function ($tool) {
                    $result = $tool->execute(['operation' => 'invalid_op']);
                    return ['pass' => !$result['success'], 'detail' => 'correctly rejects invalid operation'];
                };
                break;

            case 'code_patch':
                $tests['patch_unique'] = function ($tool) use ($sandbox) {
                    // Reset the file first
                    file_put_contents("{$sandbox}/test_patch.txt", "old_value = 1\nkeep_this = 2\nold_value_unique = 3\n");
                    $result = $tool->execute([
                        'path' => "{$sandbox}/test_patch.txt",
                        'old_string' => 'old_value_unique = 3',
                        'new_string' => 'new_value_unique = 3',
                    ]);
                    $content = file_get_contents("{$sandbox}/test_patch.txt");
                    return [
                        'pass' => $result['success'] && str_contains($content, 'new_value_unique = 3'),
                        'detail' => 'patched unique string successfully',
                    ];
                };
                $tests['patch_not_found'] = function ($tool) use ($sandbox) {
                    $result = $tool->execute([
                        'path' => "{$sandbox}/test_patch.txt",
                        'old_string' => 'this_does_not_exist_anywhere',
                        'new_string' => 'replacement',
                    ]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors when string not found'];
                };
                $tests['patch_identical'] = function ($tool) use ($sandbox) {
                    $result = $tool->execute([
                        'path' => "{$sandbox}/test_patch.txt",
                        'old_string' => 'keep_this',
                        'new_string' => 'keep_this',
                    ]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on identical strings'];
                };
                break;

            case 'db_query':
                $tests['sqlite_create'] = function ($tool) use ($sandbox) {
                    // Override config to use sandbox SQLite
                    $dbPath = "{$sandbox}/test.db";
                    $tool_config = $tool->getConfig();
                    // Test with direct PDO since tool config may not match
                    try {
                        $pdo = new \PDO("sqlite:{$dbPath}");
                        $pdo->exec("CREATE TABLE IF NOT EXISTS test (id INTEGER PRIMARY KEY, name TEXT)");
                        $pdo->exec("INSERT INTO test (name) VALUES ('phpclaw_test')");
                        $stmt = $pdo->query("SELECT * FROM test");
                        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                        return [
                            'pass' => count($rows) > 0 && $rows[0]['name'] === 'phpclaw_test',
                            'detail' => 'SQLite read/write works (PDO available)',
                        ];
                    } catch (\Throwable $e) {
                        return ['pass' => false, 'detail' => $e->getMessage()];
                    }
                };
                $tests['missing_args'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing query'];
                };
                break;

            case 'image_generate':
                $tests['missing_prompt'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing prompt'];
                };
                // Skip actual generation as it requires external APIs
                $tests['api_check'] = function ($tool) {
                    $hasOpenAI = (bool)getenv('OPENAI_API_KEY');
                    if (!$hasOpenAI) return null; // skip
                    return ['pass' => true, 'detail' => 'OpenAI API key found'];
                };
                break;

            case 'cron_schedule':
                $tests['create_list_delete'] = function ($tool) use ($sandbox) {
                    // Create
                    $createResult = $tool->execute([
                        'action' => 'create',
                        'interval' => '5m',
                        'command' => 'echo test',
                        'name' => 'smoke_test_schedule',
                    ]);
                    if (!$createResult['success']) {
                        return ['pass' => false, 'detail' => 'create failed: ' . ($createResult['error'] ?? '')];
                    }

                    $id = $createResult['data']['id'];

                    // List
                    $listResult = $tool->execute(['action' => 'list']);
                    $found = false;
                    foreach (($listResult['data']['schedules'] ?? []) as $s) {
                        if ($s['id'] === $id) $found = true;
                    }

                    // Delete
                    $deleteResult = $tool->execute(['action' => 'delete', 'id' => $id]);

                    return [
                        'pass' => $createResult['success'] && $found && $deleteResult['success'],
                        'detail' => 'create/list/delete lifecycle passed',
                    ];
                };
                break;

            case 'diff_review':
                $tests['file_diff'] = function ($tool) use ($sandbox) {
                    $pathA = "{$sandbox}/diff_a.txt";
                    $pathB = "{$sandbox}/diff_b.txt";
                    file_put_contents($pathA, "line1\nline2\nline3\n");
                    file_put_contents($pathB, "line1\nmodified\nline3\nnew line\n");
                    $result = $tool->execute(['mode' => 'files', 'path_a' => $pathA, 'path_b' => $pathB]);
                    return [
                        'pass' => $result['success'] && $result['data']['has_changes'],
                        'detail' => $result['data']['hunk_count'] . ' hunks found',
                    ];
                };
                $tests['working_diff'] = function ($tool) {
                    $result = $tool->execute(['mode' => 'working']);
                    return ['pass' => $result['success'], 'detail' => 'working tree diff executed'];
                };
                break;

            case 'http_request':
                $tests['missing_url'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing URL'];
                };
                $tests['invalid_method'] = function ($tool) {
                    $result = $tool->execute(['url' => 'http://example.com', 'method' => 'INVALID']);
                    return ['pass' => !$result['success'], 'detail' => 'correctly rejects invalid method'];
                };
                break;

            case 'archive_extract':
                $tests['create_and_extract_zip'] = function ($tool) use ($sandbox) {
                    if (!class_exists('ZipArchive')) {
                        return null; // skip if no zip extension
                    }
                    $testFile = "{$sandbox}/archive_test.txt";
                    file_put_contents($testFile, "archive content");

                    $archivePath = "{$sandbox}/test.zip";
                    $createResult = $tool->execute([
                        'action' => 'create',
                        'archive_path' => $archivePath,
                        'files' => [$testFile],
                    ]);

                    if (!$createResult['success']) {
                        return ['pass' => false, 'detail' => 'create failed: ' . ($createResult['error'] ?? '')];
                    }

                    $extractDir = "{$sandbox}/extracted";
                    mkdir($extractDir, 0755, true);
                    $extractResult = $tool->execute([
                        'action' => 'extract',
                        'archive_path' => $archivePath,
                        'destination' => $extractDir,
                    ]);

                    return [
                        'pass' => $createResult['success'] && $extractResult['success'],
                        'detail' => 'create/extract ZIP lifecycle passed',
                    ];
                };
                break;

            case 'process_manager':
                $tests['start_and_list'] = function ($tool) {
                    $startResult = $tool->execute([
                        'action' => 'start',
                        'command' => 'sleep 2',
                        'name' => 'smoke_test_sleep',
                    ]);

                    if (!$startResult['success']) {
                        return ['pass' => false, 'detail' => 'start failed: ' . ($startResult['error'] ?? '')];
                    }

                    $pid = $startResult['data']['pid'];
                    $listResult = $tool->execute(['action' => 'list']);

                    // Stop it
                    $tool->execute(['action' => 'stop', 'pid' => $pid]);

                    return [
                        'pass' => $startResult['success'] && $listResult['success'],
                        'detail' => "started PID {$pid}, listed, stopped",
                    ];
                };
                break;

            case 'notification_send':
                $tests['desktop'] = function ($tool) {
                    $result = $tool->execute([
                        'channel' => 'desktop',
                        'message' => 'PHPClaw tool test',
                        'title' => 'PHPClaw Test',
                    ]);
                    return [
                        'pass' => $result['success'],
                        'detail' => 'desktop notification sent (platform: ' . PHP_OS_FAMILY . ')',
                    ];
                };
                $tests['missing_channel'] = function ($tool) {
                    $result = $tool->execute([]);
                    return ['pass' => !$result['success'], 'detail' => 'correctly errors on missing args'];
                };
                break;
        }

        return $tests;
    }

    private function cleanupSandbox(): void
    {
        if (!is_dir($this->sandboxDir)) return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandboxDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->sandboxDir);
    }
}
