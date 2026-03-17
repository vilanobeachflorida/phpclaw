<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;

/**
 * Interactive setup wizard for PHPClaw.
 * Creates all required directories, config files, and validates the environment.
 */
class SetupCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:setup';
    protected $description = 'Run the PHPClaw setup wizard';

    public function run(array $params)
    {
        CLI::newLine();
        CLI::write('========================================', 'green');
        CLI::write('  PHPClaw Setup Wizard', 'green');
        CLI::write('========================================', 'green');
        CLI::newLine();

        // Step 1: Check PHP environment
        $this->stepEnvironment();

        // Step 2: Create directory structure
        $this->stepDirectories();

        // Step 3: Create/update config files
        $this->stepConfig();

        // Step 4: Configure provider
        $this->stepProvider();

        // Step 5: Initialize state files
        $this->stepState();

        // Step 6: Create prompt files
        $this->stepPrompts();

        // Step 7: Validate
        $this->stepValidate();

        CLI::newLine();
        CLI::write('========================================', 'green');
        CLI::write('  Setup Complete!', 'green');
        CLI::write('========================================', 'green');
        CLI::newLine();
        CLI::write('Next steps:', 'yellow');
        CLI::write('  php spark agent:status     Check system status');
        CLI::write('  php spark agent:providers   Check provider connectivity');
        CLI::write('  php spark agent:chat        Start chatting');
        CLI::write('  php spark agent:serve       Start background service');
        CLI::newLine();
    }

    private function stepEnvironment(): void
    {
        CLI::write('[1/7] Checking environment...', 'yellow');

        $checks = [
            ['PHP version >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['curl extension', extension_loaded('curl')],
            ['json extension', extension_loaded('json')],
            ['mbstring extension', extension_loaded('mbstring')],
            ['pcntl extension (signals)', extension_loaded('pcntl')],
        ];

        $allGood = true;
        foreach ($checks as [$label, $ok]) {
            $status = $ok ? 'OK' : 'MISSING';
            $color = $ok ? 'green' : 'red';
            CLI::write("  {$label}: {$status}", $color);
            if (!$ok && $label !== 'pcntl extension (signals)') {
                $allGood = false;
            }
        }

        CLI::write("  PHP version: " . PHP_VERSION, 'light_gray');
        CLI::write("  OS: " . PHP_OS, 'light_gray');

        if (!$allGood) {
            CLI::write('  WARNING: Some required extensions are missing.', 'red');
        }

        // Check writable directory
        $writablePath = WRITEPATH;
        $writable = is_writable($writablePath);
        CLI::write("  Writable directory: " . ($writable ? 'OK' : 'NOT WRITABLE'), $writable ? 'green' : 'red');

        if (!$writable) {
            CLI::error("Cannot continue: {$writablePath} is not writable.");
            exit(1);
        }

        CLI::newLine();
    }

    private function stepDirectories(): void
    {
        CLI::write('[2/7] Creating directory structure...', 'yellow');

        $basePath = WRITEPATH . 'agent';
        $dirs = [
            'config',
            'sessions',
            'tasks',
            'memory/global/compacted',
            'memory/global/summaries',
            'memory/sessions',
            'memory/modules',
            'memory/tasks',
            'cache/llm',
            'cache/tools',
            'cache/browser',
            'cache/providers',
            'cache/manifests',
            'logs',
            'prompts/system',
            'prompts/roles',
            'prompts/modules',
            'prompts/tasks',
            'state',
            'queues',
            'locks',
        ];

        $created = 0;
        $existed = 0;
        foreach ($dirs as $dir) {
            $fullPath = $basePath . '/' . $dir;
            if (is_dir($fullPath)) {
                $existed++;
            } else {
                mkdir($fullPath, 0755, true);
                $created++;
            }
        }

        CLI::write("  Created: {$created} directories", 'green');
        CLI::write("  Already existed: {$existed} directories", 'light_gray');
        CLI::newLine();
    }

    private function stepConfig(): void
    {
        CLI::write('[3/7] Setting up configuration...', 'yellow');

        $storage = new FileStorage();

        $configs = [
            'app' => [
                'name' => 'PHPClaw',
                'version' => '0.1.0',
                'description' => 'Terminal-first multi-model AI agent shell',
                'debug' => false,
                'verbose' => false,
                'timezone' => 'UTC',
                'storage_path' => 'writable/agent',
                'default_provider' => 'ollama',
                'default_model' => 'llama3',
                'default_role' => 'reasoning',
                'default_module' => 'reasoning',
                'session' => [
                    'auto_save' => true,
                    'max_transcript_lines' => 10000,
                    'default_name_prefix' => 'session',
                ],
                'memory' => [
                    'enabled' => true,
                    'compaction_interval' => 3600,
                    'max_notes_before_compaction' => 500,
                    'summary_max_length' => 2000,
                ],
                'cache' => [
                    'enabled' => true,
                    'default_ttl' => 3600,
                    'max_size_mb' => 500,
                ],
            ],
            'roles' => [
                'roles' => [
                    'heartbeat' => [
                        'description' => 'Lightweight health check role',
                        'provider' => 'ollama', 'model' => 'llama3',
                        'fallback' => [], 'timeout' => 10, 'retry' => 1,
                    ],
                    'reasoning' => [
                        'description' => 'Deep reasoning and analysis',
                        'provider' => 'ollama', 'model' => 'llama3',
                        'fallback' => ['openllm'], 'timeout' => 120, 'retry' => 2,
                    ],
                    'coding' => [
                        'description' => 'Code generation and analysis',
                        'provider' => 'claude_code', 'model' => 'default',
                        'fallback' => ['ollama'], 'timeout' => 180, 'retry' => 2,
                    ],
                    'summarization' => [
                        'description' => 'Fast summarization tasks',
                        'provider' => 'ollama', 'model' => 'llama3',
                        'fallback' => [], 'timeout' => 60, 'retry' => 1,
                    ],
                    'planning' => [
                        'description' => 'Task planning and decomposition',
                        'provider' => 'ollama', 'model' => 'llama3',
                        'fallback' => [], 'timeout' => 120, 'retry' => 2,
                    ],
                    'browser' => [
                        'description' => 'Web content processing',
                        'provider' => 'ollama', 'model' => 'llama3',
                        'fallback' => [], 'timeout' => 60, 'retry' => 1,
                    ],
                    'memory_compaction' => [
                        'description' => 'Memory compaction and summarization',
                        'provider' => 'ollama', 'model' => 'llama3',
                        'fallback' => [], 'timeout' => 120, 'retry' => 1,
                    ],
                    'fast_response' => [
                        'description' => 'Quick responses for simple queries',
                        'provider' => 'ollama', 'model' => 'llama3',
                        'fallback' => [], 'timeout' => 30, 'retry' => 1,
                    ],
                ],
            ],
            'modules' => [
                'modules' => [
                    'heartbeat' => [
                        'enabled' => true, 'description' => 'System health monitoring',
                        'role' => 'heartbeat', 'provider_override' => null, 'model_override' => null,
                        'tools' => [], 'cache_policy' => 'none', 'memory_policy' => 'none',
                        'timeout' => 10, 'retry' => 1, 'prompt_file' => 'modules/heartbeat.md',
                    ],
                    'reasoning' => [
                        'enabled' => true, 'description' => 'Deep reasoning and analysis',
                        'role' => 'reasoning', 'provider_override' => null, 'model_override' => null,
                        'tools' => ['file_read', 'grep_search', 'dir_list'],
                        'cache_policy' => 'standard', 'memory_policy' => 'full',
                        'timeout' => 120, 'retry' => 2, 'prompt_file' => 'modules/reasoning.md',
                    ],
                    'coding' => [
                        'enabled' => true, 'description' => 'Code generation and modification',
                        'role' => 'coding', 'provider_override' => null, 'model_override' => null,
                        'tools' => ['file_read', 'file_write', 'file_append', 'dir_list', 'mkdir', 'grep_search', 'shell_exec'],
                        'cache_policy' => 'none', 'memory_policy' => 'full',
                        'timeout' => 180, 'retry' => 2, 'prompt_file' => 'modules/coding.md',
                    ],
                    'summarizer' => [
                        'enabled' => true, 'description' => 'Content summarization',
                        'role' => 'summarization', 'provider_override' => null, 'model_override' => null,
                        'tools' => ['file_read'], 'cache_policy' => 'aggressive', 'memory_policy' => 'summary_only',
                        'timeout' => 60, 'retry' => 1, 'prompt_file' => 'modules/summarizer.md',
                    ],
                    'memory' => [
                        'enabled' => true, 'description' => 'Memory management and compaction',
                        'role' => 'memory_compaction', 'provider_override' => null, 'model_override' => null,
                        'tools' => ['file_read', 'file_write', 'dir_list'],
                        'cache_policy' => 'none', 'memory_policy' => 'none',
                        'timeout' => 120, 'retry' => 1, 'prompt_file' => 'modules/memory.md',
                    ],
                    'planner' => [
                        'enabled' => true, 'description' => 'Task planning and decomposition',
                        'role' => 'planning', 'provider_override' => null, 'model_override' => null,
                        'tools' => ['file_read', 'dir_list', 'grep_search'],
                        'cache_policy' => 'standard', 'memory_policy' => 'full',
                        'timeout' => 120, 'retry' => 2, 'prompt_file' => 'modules/planner.md',
                    ],
                    'browser' => [
                        'enabled' => true, 'description' => 'Web content fetching and processing',
                        'role' => 'browser', 'provider_override' => null, 'model_override' => null,
                        'tools' => ['browser_fetch', 'browser_text', 'http_get'],
                        'cache_policy' => 'standard', 'memory_policy' => 'summary_only',
                        'timeout' => 60, 'retry' => 1, 'prompt_file' => 'modules/browser.md',
                    ],
                    'tool_router' => [
                        'enabled' => true, 'description' => 'Routes tool execution requests',
                        'role' => 'fast_response', 'provider_override' => null, 'model_override' => null,
                        'tools' => ['*'], 'cache_policy' => 'none', 'memory_policy' => 'full',
                        'timeout' => 30, 'retry' => 1, 'prompt_file' => 'modules/tool_router.md',
                    ],
                ],
            ],
            'providers' => [
                'providers' => [
                    'ollama' => [
                        'enabled' => true, 'type' => 'ollama',
                        'description' => 'Local Ollama instance',
                        'base_url' => 'http://localhost:11434',
                        'default_model' => 'llama3', 'timeout' => 120, 'retry' => 2,
                        'options' => [],
                    ],
                    'openllm' => [
                        'enabled' => false, 'type' => 'openllm',
                        'description' => 'OpenAI-compatible LLM endpoint',
                        'base_url' => 'http://localhost:8000',
                        'api_key_env' => 'OPENLLM_API_KEY',
                        'default_model' => 'default', 'timeout' => 120, 'retry' => 2,
                        'headers' => [], 'options' => [],
                    ],
                    'claude_code' => [
                        'enabled' => false, 'type' => 'claude_code',
                        'description' => 'Claude Code local CLI runtime',
                        'command' => 'claude', 'timeout' => 180, 'retry' => 1, 'options' => [],
                    ],
                    'chatgpt' => [
                        'enabled' => false, 'type' => 'chatgpt',
                        'description' => 'ChatGPT via OpenAI API (key or OAuth)',
                        'base_url' => 'https://api.openai.com/v1',
                        'api_key_env' => 'OPENAI_API_KEY',
                        'default_model' => 'gpt-4', 'timeout' => 120, 'retry' => 2, 'options' => [],
                        'oauth' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
                    ],
                    'claude_api' => [
                        'enabled' => false, 'type' => 'claude_api',
                        'description' => 'Claude via Anthropic API (key or OAuth)',
                        'base_url' => 'https://api.anthropic.com',
                        'api_key_env' => 'ANTHROPIC_API_KEY',
                        'default_model' => 'claude-sonnet-4-20250514', 'api_version' => '2023-06-01',
                        'max_tokens' => 4096, 'timeout' => 180, 'retry' => 2, 'options' => [],
                        'oauth' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
                    ],
                ],
            ],
            'tools' => [
                'tools' => [
                    'file_read' => ['enabled' => true, 'description' => 'Read file contents', 'timeout' => 10],
                    'file_write' => ['enabled' => true, 'description' => 'Write content to file', 'timeout' => 10],
                    'file_append' => ['enabled' => true, 'description' => 'Append content to file', 'timeout' => 10],
                    'dir_list' => ['enabled' => true, 'description' => 'List directory contents', 'timeout' => 10],
                    'mkdir' => ['enabled' => true, 'description' => 'Create directory', 'timeout' => 10],
                    'move_file' => ['enabled' => true, 'description' => 'Move or rename file', 'timeout' => 10],
                    'delete_file' => ['enabled' => true, 'description' => 'Delete a file', 'timeout' => 10],
                    'grep_search' => ['enabled' => true, 'description' => 'Search file contents with patterns', 'timeout' => 30],
                    'http_get' => ['enabled' => true, 'description' => 'Make HTTP GET request', 'timeout' => 30],
                    'browser_fetch' => ['enabled' => true, 'description' => 'Fetch web page content', 'timeout' => 30],
                    'browser_text' => ['enabled' => true, 'description' => 'Extract text from web page', 'timeout' => 30],
                    'shell_exec' => ['enabled' => true, 'description' => 'Execute shell command', 'timeout' => 60],
                    'system_info' => ['enabled' => true, 'description' => 'Get system information', 'timeout' => 10],
                ],
            ],
            'service' => [
                'service' => [
                    'enabled' => true,
                    'loop_interval_ms' => 1000,
                    'heartbeat_interval' => 60,
                    'maintenance_interval' => 3600,
                    'provider_health_interval' => 300,
                    'task_check_interval' => 5,
                    'memory_compaction_interval' => 3600,
                    'cache_prune_interval' => 7200,
                    'max_concurrent_tasks' => 3,
                    'log_file' => 'writable/agent/logs/service.log',
                    'pid_file' => 'writable/agent/state/service.pid',
                    'state_file' => 'writable/agent/state/service.json',
                ],
            ],
        ];

        $written = 0;
        $skipped = 0;
        foreach ($configs as $name => $data) {
            $path = "config/{$name}.json";
            if ($storage->exists($path)) {
                CLI::write("  {$name}.json: exists (kept)", 'light_gray');
                $skipped++;
            } else {
                $storage->writeJson($path, $data);
                CLI::write("  {$name}.json: created", 'green');
                $written++;
            }
        }

        CLI::write("  Written: {$written}, Skipped: {$skipped}", 'light_gray');
        CLI::newLine();
    }

    private function stepProvider(): void
    {
        CLI::write('[4/7] Configuring primary provider...', 'yellow');

        $storage = new FileStorage();
        $config = new ConfigLoader($storage);
        $providersConfig = $config->load('providers');

        CLI::write('  Which provider would you like to use?', 'white');
        CLI::write('  1) Ollama (local, recommended for getting started)');
        CLI::write('  2) OpenAI / ChatGPT - API key');
        CLI::write('  3) OpenAI / ChatGPT - OAuth');
        CLI::write('  4) Claude API (Anthropic) - API key');
        CLI::write('  5) Claude API (Anthropic) - OAuth');
        CLI::write('  6) Claude Code (local CLI)');
        CLI::write('  7) OpenLLM-compatible endpoint (custom URL)');
        CLI::write('  8) Skip (configure later)');

        $choice = CLI::prompt('  Select provider', '1');

        switch ($choice) {
            case '1':
                $this->configureOllama($storage, $providersConfig);
                break;
            case '2':
                $this->configureChatGPT($storage, $providersConfig);
                break;
            case '3':
                $this->configureChatGPTOAuth($storage, $providersConfig);
                break;
            case '4':
                $this->configureClaudeAPI($storage, $providersConfig);
                break;
            case '5':
                $this->configureClaudeAPIOAuth($storage, $providersConfig);
                break;
            case '6':
                $this->configureClaudeCode($storage, $providersConfig);
                break;
            case '7':
                $this->configureOpenLLM($storage, $providersConfig);
                break;
            case '8':
                CLI::write('  Skipped. Configure providers later in writable/agent/config/providers.json', 'light_gray');
                break;
            default:
                CLI::write('  Invalid choice. Skipped.', 'red');
        }

        CLI::newLine();
    }

    private function configureOllama(FileStorage $storage, array $providersConfig): void
    {
        $baseUrl = CLI::prompt('  Ollama URL', 'http://localhost:11434');
        $model = CLI::prompt('  Default model', 'llama3');

        $providersConfig['providers']['ollama']['enabled'] = true;
        $providersConfig['providers']['ollama']['base_url'] = $baseUrl;
        $providersConfig['providers']['ollama']['default_model'] = $model;
        $storage->writeJson('config/providers.json', $providersConfig);

        // Update roles to use this provider/model
        $this->updateDefaultProvider($storage, 'ollama', $model);

        // Test connection
        CLI::write('  Testing Ollama connection...', 'yellow');
        $ch = curl_init($baseUrl . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
        $result = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            CLI::write("  WARNING: Cannot reach Ollama at {$baseUrl}", 'red');
            CLI::write("  Make sure Ollama is running: ollama serve", 'yellow');
        } else {
            CLI::write("  Ollama is reachable!", 'green');
            $data = json_decode($result, true);
            $models = $data['models'] ?? [];
            if (!empty($models)) {
                CLI::write('  Available models:');
                foreach (array_slice($models, 0, 10) as $m) {
                    CLI::write("    - {$m['name']}");
                }
            }
        }
    }

    private function configureChatGPT(FileStorage $storage, array $providersConfig): void
    {
        $apiKey = CLI::prompt('  OpenAI API key (or env var name)');
        $model = CLI::prompt('  Default model', 'gpt-4');

        if (str_starts_with($apiKey, 'sk-')) {
            CLI::write('  TIP: Store API keys in .env instead of config files.', 'yellow');
            CLI::write('  Add OPENAI_API_KEY=' . $apiKey . ' to your .env file.', 'yellow');
            $providersConfig['providers']['chatgpt']['api_key_env'] = 'OPENAI_API_KEY';
        } else {
            $providersConfig['providers']['chatgpt']['api_key_env'] = $apiKey ?: 'OPENAI_API_KEY';
        }

        $providersConfig['providers']['chatgpt']['enabled'] = true;
        $providersConfig['providers']['chatgpt']['default_model'] = $model;
        $storage->writeJson('config/providers.json', $providersConfig);

        $this->updateDefaultProvider($storage, 'chatgpt', $model);
        CLI::write('  ChatGPT configured.', 'green');
    }

    private function configureChatGPTOAuth(FileStorage $storage, array $providersConfig): void
    {
        CLI::write('  Setting up ChatGPT with OAuth...', 'cyan');
        CLI::newLine();
        CLI::write('  You need an OAuth client ID from OpenAI.', 'light_gray');
        CLI::write('  See: https://platform.openai.com/docs/guides/oauth', 'light_gray');
        CLI::newLine();

        $clientId = CLI::prompt('  OAuth Client ID');
        $clientSecret = CLI::prompt('  OAuth Client Secret (optional, press Enter to skip)');
        $model = CLI::prompt('  Default model', 'gpt-4');

        $providersConfig['providers']['chatgpt']['enabled'] = true;
        $providersConfig['providers']['chatgpt']['default_model'] = $model;
        $providersConfig['providers']['chatgpt']['oauth'] = [
            'enabled' => true,
            'client_id' => $clientId,
            'client_secret' => $clientSecret ?: '',
        ];
        $storage->writeJson('config/providers.json', $providersConfig);

        $this->updateDefaultProvider($storage, 'chatgpt', $model);
        CLI::write('  ChatGPT OAuth configured.', 'green');
        CLI::write('  Run `php spark agent:auth login chatgpt` to complete login.', 'yellow');
    }

    private function configureClaudeAPI(FileStorage $storage, array $providersConfig): void
    {
        $apiKey = CLI::prompt('  Anthropic API key (or env var name)');
        $model = CLI::prompt('  Default model', 'claude-sonnet-4-20250514');

        if (str_starts_with($apiKey, 'sk-ant-')) {
            CLI::write('  TIP: Store API keys in .env instead of config files.', 'yellow');
            CLI::write('  Add ANTHROPIC_API_KEY=' . $apiKey . ' to your .env file.', 'yellow');
            $providersConfig['providers']['claude_api']['api_key_env'] = 'ANTHROPIC_API_KEY';
        } else {
            $providersConfig['providers']['claude_api']['api_key_env'] = $apiKey ?: 'ANTHROPIC_API_KEY';
        }

        $providersConfig['providers']['claude_api']['enabled'] = true;
        $providersConfig['providers']['claude_api']['default_model'] = $model;
        $storage->writeJson('config/providers.json', $providersConfig);

        $this->updateDefaultProvider($storage, 'claude_api', $model);
        CLI::write('  Claude API configured.', 'green');
    }

    private function configureClaudeAPIOAuth(FileStorage $storage, array $providersConfig): void
    {
        CLI::write('  Setting up Claude API with OAuth...', 'cyan');
        CLI::newLine();
        CLI::write('  You need an OAuth client ID from Anthropic.', 'light_gray');
        CLI::write('  See: https://docs.anthropic.com/en/docs/oauth', 'light_gray');
        CLI::newLine();

        $clientId = CLI::prompt('  OAuth Client ID');
        $clientSecret = CLI::prompt('  OAuth Client Secret (optional, press Enter to skip)');
        $model = CLI::prompt('  Default model', 'claude-sonnet-4-20250514');

        $providersConfig['providers']['claude_api']['enabled'] = true;
        $providersConfig['providers']['claude_api']['default_model'] = $model;
        $providersConfig['providers']['claude_api']['oauth'] = [
            'enabled' => true,
            'client_id' => $clientId,
            'client_secret' => $clientSecret ?: '',
        ];
        $storage->writeJson('config/providers.json', $providersConfig);

        $this->updateDefaultProvider($storage, 'claude_api', $model);
        CLI::write('  Claude API OAuth configured.', 'green');
        CLI::write('  Run `php spark agent:auth login claude_api` to complete login.', 'yellow');
    }

    private function configureClaudeCode(FileStorage $storage, array $providersConfig): void
    {
        $command = CLI::prompt('  Claude CLI command', 'claude');

        // Check if available
        $output = shell_exec("which {$command} 2>/dev/null") ?? shell_exec("where {$command} 2>/dev/null");
        if (empty(trim($output ?? ''))) {
            CLI::write("  WARNING: '{$command}' not found in PATH.", 'red');
            CLI::write('  Install Claude Code CLI first.', 'yellow');
        } else {
            CLI::write("  Found: " . trim($output), 'green');
        }

        $providersConfig['providers']['claude_code']['enabled'] = true;
        $providersConfig['providers']['claude_code']['command'] = $command;
        $storage->writeJson('config/providers.json', $providersConfig);

        $this->updateDefaultProvider($storage, 'claude_code', 'default');
        CLI::write('  Claude Code configured.', 'green');
    }

    private function configureOpenLLM(FileStorage $storage, array $providersConfig): void
    {
        $baseUrl = CLI::prompt('  Endpoint base URL', 'http://localhost:8000');
        $model = CLI::prompt('  Default model name', 'default');
        $apiKey = CLI::prompt('  API key env var (or leave blank)', 'OPENLLM_API_KEY');

        $providersConfig['providers']['openllm']['enabled'] = true;
        $providersConfig['providers']['openllm']['base_url'] = $baseUrl;
        $providersConfig['providers']['openllm']['default_model'] = $model;
        $providersConfig['providers']['openllm']['api_key_env'] = $apiKey;
        $storage->writeJson('config/providers.json', $providersConfig);

        $this->updateDefaultProvider($storage, 'openllm', $model);
        CLI::write('  OpenLLM endpoint configured.', 'green');
    }

    private function updateDefaultProvider(FileStorage $storage, string $provider, string $model): void
    {
        // Update app.json defaults
        $appConfig = $storage->readJson('config/app.json') ?? [];
        $appConfig['default_provider'] = $provider;
        $appConfig['default_model'] = $model;
        $storage->writeJson('config/app.json', $appConfig);

        // Update all roles to use this provider/model
        $rolesConfig = $storage->readJson('config/roles.json') ?? ['roles' => []];
        foreach ($rolesConfig['roles'] as $name => &$role) {
            $role['provider'] = $provider;
            $role['model'] = $model;
        }
        $storage->writeJson('config/roles.json', $rolesConfig);
    }

    private function stepState(): void
    {
        CLI::write('[5/7] Initializing state files...', 'yellow');

        $storage = new FileStorage();

        $stateFiles = [
            'state/service.json' => ['status' => 'stopped', 'started_at' => null, 'last_heartbeat' => null, 'uptime_seconds' => 0, 'tasks_processed' => 0, 'errors' => 0],
            'state/heartbeat.json' => ['last_check' => null, 'status' => 'unknown', 'providers' => []],
            'state/active-tasks.json' => ['tasks' => []],
            'state/provider-health.json' => ['providers' => [], 'last_check' => null],
            'state/loop.json' => ['iteration' => 0, 'last_tick' => null, 'status' => 'idle'],
            'sessions/index.json' => ['sessions' => [], 'active_session' => null],
            'tasks/index.json' => ['tasks' => []],
            'memory/global/index.json' => ['total_notes' => 0, 'total_summaries' => 0, 'last_compaction' => null, 'compaction_count' => 0],
        ];

        $written = 0;
        $skipped = 0;
        foreach ($stateFiles as $path => $data) {
            if ($storage->exists($path)) {
                $skipped++;
            } else {
                $storage->writeJson($path, $data);
                $written++;
            }
        }

        CLI::write("  Written: {$written}, Skipped: {$skipped}", 'green');
        CLI::newLine();
    }

    private function stepPrompts(): void
    {
        CLI::write('[6/7] Creating prompt templates...', 'yellow');

        $storage = new FileStorage();

        $prompts = [
            'prompts/system/default.md' => "You are PHPClaw, a terminal-native AI agent assistant. You help users with tasks by leveraging available tools and your reasoning capabilities. Be concise, helpful, and action-oriented.\n",
            'prompts/modules/heartbeat.md' => "You are a system health monitor. Respond with a brief status confirmation. Keep responses under 50 words.\n",
            'prompts/modules/reasoning.md' => "You are an expert reasoning assistant. Think step-by-step, analyze problems carefully, and provide well-structured responses. When solving problems, break them down into clear logical steps.\n",
            'prompts/modules/coding.md' => "You are an expert software engineer. Write clean, well-structured code. Follow best practices and conventions. Explain your approach when the solution is non-obvious.\n",
            'prompts/modules/summarizer.md' => "You are a summarization assistant. Provide concise, accurate summaries that preserve key information. Focus on the most important points and actionable items.\n",
            'prompts/modules/memory.md' => "You are a memory management assistant. Analyze conversation logs and extract key facts, decisions, and actionable information. Create concise summaries that preserve meaning while reducing size.\n",
            'prompts/modules/planner.md' => "You are a task planning assistant. Break down complex tasks into clear, actionable steps. Consider dependencies, risks, and optimal execution order.\n",
            'prompts/modules/browser.md' => "You are a web content processing assistant. Analyze fetched web content and extract relevant information. Summarize key points from web pages.\n",
            'prompts/modules/tool_router.md' => "You are a tool routing assistant. When given a task, determine which tools to use and in what order. Coordinate tool execution for multi-step operations.\n",
        ];

        $written = 0;
        $skipped = 0;
        foreach ($prompts as $path => $content) {
            if ($storage->exists($path)) {
                $skipped++;
            } else {
                $storage->writeText($path, $content);
                $written++;
            }
        }

        CLI::write("  Written: {$written}, Skipped: {$skipped}", 'green');
        CLI::newLine();
    }

    private function stepValidate(): void
    {
        CLI::write('[7/7] Validating setup...', 'yellow');

        $storage = new FileStorage();
        $errors = [];

        // Check essential config files
        $requiredConfigs = ['config/app.json', 'config/providers.json', 'config/roles.json', 'config/modules.json', 'config/tools.json', 'config/service.json'];
        foreach ($requiredConfigs as $file) {
            if (!$storage->exists($file)) {
                $errors[] = "Missing config: {$file}";
            }
        }

        // Check state files
        $requiredState = ['state/service.json', 'sessions/index.json', 'tasks/index.json'];
        foreach ($requiredState as $file) {
            if (!$storage->exists($file)) {
                $errors[] = "Missing state: {$file}";
            }
        }

        // Check at least one provider is enabled
        $config = new ConfigLoader($storage);
        $providers = $config->get('providers', 'providers', []);
        $enabledCount = 0;
        foreach ($providers as $p) {
            if ($p['enabled'] ?? false) $enabledCount++;
        }

        if ($enabledCount === 0) {
            $errors[] = 'No providers enabled. Configure at least one in providers.json.';
        }

        // Check directories
        $requiredDirs = ['logs', 'cache', 'memory', 'sessions', 'tasks', 'state', 'config', 'prompts'];
        foreach ($requiredDirs as $dir) {
            if (!is_dir($storage->path($dir))) {
                $errors[] = "Missing directory: {$dir}";
            }
        }

        if (empty($errors)) {
            CLI::write('  All checks passed!', 'green');
        } else {
            CLI::write('  Issues found:', 'red');
            foreach ($errors as $err) {
                CLI::write("    - {$err}", 'red');
            }
        }

        // Summary
        CLI::newLine();
        CLI::write('  Storage: ' . $storage->getBasePath(), 'light_gray');
        CLI::write("  Enabled providers: {$enabledCount}", 'light_gray');
        CLI::write('  Config files: ' . count($requiredConfigs), 'light_gray');
    }
}
