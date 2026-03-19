<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;

/**
 * Reset all agent config files to their shipped defaults.
 *
 * This restores every JSON config in writable/agent/config/ to the
 * factory state — useful after experimentation, before committing,
 * or when a config gets corrupted.
 */
class ConfigResetCommand extends BaseCommand
{
    protected $group       = 'agent';
    protected $name        = 'agent:config:reset';
    protected $description = 'Reset agent config files to shipped defaults';
    protected $usage       = 'agent:config:reset [--yes] [--file <name>]';

    /**
     * Shipped defaults for every config file.
     * This is the single source of truth for factory state.
     */
    private function getDefaults(): array
    {
        return [
            'app' => [
                'name'             => 'PHPClaw',
                'version'          => '0.1.0',
                'description'      => 'Terminal-first multi-model AI agent shell',
                'debug'            => false,
                'verbose'          => false,
                'timezone'         => 'UTC',
                'storage_path'     => 'writable/agent',
                'default_provider' => 'lmstudio',
                'default_model'    => 'default',
                'default_role'     => 'reasoning',
                'default_module'   => 'reasoning',
                'session' => [
                    'auto_save'              => true,
                    'max_transcript_lines'   => 10000,
                    'default_name_prefix'    => 'session',
                ],
                'memory' => [
                    'enabled'                     => true,
                    'compaction_interval'          => 3600,
                    'max_notes_before_compaction'  => 500,
                    'summary_max_length'           => 2000,
                ],
                'cache' => [
                    'enabled'     => true,
                    'default_ttl' => 3600,
                    'max_size_mb' => 500,
                ],
                'service' => [
                    'loop_interval_ms'        => 1000,
                    'heartbeat_interval'      => 60,
                    'maintenance_interval'    => 3600,
                    'provider_health_interval' => 300,
                ],
            ],

            'providers' => [
                'providers' => [
                    'ollama' => [
                        'enabled'       => false,
                        'type'          => 'ollama',
                        'description'   => 'Local Ollama instance',
                        'base_url'      => 'http://localhost:11434',
                        'default_model' => 'llama3',
                        'timeout'       => 120,
                        'retry'         => 2,
                        'options'       => [],
                    ],
                    'lmstudio' => [
                        'enabled'       => false,
                        'type'          => 'lmstudio',
                        'description'   => 'LM Studio local server',
                        'base_url'      => 'http://localhost:1234',
                        'default_model' => 'default',
                        'timeout'       => 120,
                        'retry'         => 2,
                        'options'       => [],
                    ],
                    'openllm' => [
                        'enabled'       => false,
                        'type'          => 'openllm',
                        'description'   => 'OpenAI-compatible LLM endpoint',
                        'base_url'      => 'http://localhost:8000',
                        'api_key_env'   => 'OPENLLM_API_KEY',
                        'default_model' => 'default',
                        'timeout'       => 120,
                        'retry'         => 2,
                        'headers'       => [],
                        'options'       => [],
                    ],
                    'claude_code' => [
                        'enabled'     => false,
                        'type'        => 'claude_code',
                        'description' => 'Claude Code local CLI runtime',
                        'command'     => 'claude',
                        'timeout'     => 180,
                        'retry'       => 1,
                        'options'     => [],
                    ],
                    'chatgpt' => [
                        'enabled'       => false,
                        'type'          => 'chatgpt',
                        'description'   => 'ChatGPT via OpenAI API (key or OAuth)',
                        'base_url'      => 'https://api.openai.com/v1',
                        'api_key_env'   => 'OPENAI_API_KEY',
                        'default_model' => 'gpt-4',
                        'timeout'       => 120,
                        'retry'         => 2,
                        'options'       => [],
                        'oauth'         => [
                            'enabled'       => false,
                            'client_id'     => '',
                            'client_secret' => '',
                        ],
                    ],
                    'claude_api' => [
                        'enabled'       => false,
                        'type'          => 'claude_api',
                        'description'   => 'Claude via Anthropic API (key or OAuth)',
                        'base_url'      => 'https://api.anthropic.com',
                        'api_key_env'   => 'ANTHROPIC_API_KEY',
                        'default_model' => 'claude-sonnet-4-20250514',
                        'api_version'   => '2023-06-01',
                        'max_tokens'    => 4096,
                        'timeout'       => 180,
                        'retry'         => 2,
                        'options'       => [],
                        'oauth'         => [
                            'enabled'       => false,
                            'client_id'     => '',
                            'client_secret' => '',
                        ],
                    ],
                ],
            ],

            'roles' => [
                'roles' => [
                    'heartbeat' => [
                        'description' => 'Lightweight health check role',
                        'provider'    => 'lmstudio',
                        'model'       => 'default',
                        'fallback'    => [],
                        'timeout'     => 10,
                        'retry'       => 1,
                    ],
                    'reasoning' => [
                        'description' => 'Deep reasoning and analysis',
                        'provider'    => 'lmstudio',
                        'model'       => 'default',
                        'fallback'    => ['openllm'],
                        'timeout'     => 120,
                        'retry'       => 2,
                    ],
                    'coding' => [
                        'description' => 'Code generation and analysis',
                        'provider'    => 'lmstudio',
                        'model'       => 'default',
                        'fallback'    => [],
                        'timeout'     => 180,
                        'retry'       => 2,
                    ],
                    'summarization' => [
                        'description' => 'Fast summarization tasks',
                        'provider'    => 'lmstudio',
                        'model'       => 'default',
                        'fallback'    => [],
                        'timeout'     => 60,
                        'retry'       => 1,
                    ],
                    'planning' => [
                        'description' => 'Task planning and decomposition',
                        'provider'    => 'lmstudio',
                        'model'       => 'default',
                        'fallback'    => [],
                        'timeout'     => 120,
                        'retry'       => 2,
                    ],
                    'browser' => [
                        'description' => 'Web content processing',
                        'provider'    => 'lmstudio',
                        'model'       => 'default',
                        'fallback'    => [],
                        'timeout'     => 60,
                        'retry'       => 1,
                    ],
                    'memory_compaction' => [
                        'description' => 'Memory compaction and summarization',
                        'provider'    => 'lmstudio',
                        'model'       => 'default',
                        'fallback'    => [],
                        'timeout'     => 120,
                        'retry'       => 1,
                    ],
                    'fast_response' => [
                        'description' => 'Quick responses for simple queries',
                        'provider'    => 'lmstudio',
                        'model'       => 'default',
                        'fallback'    => [],
                        'timeout'     => 30,
                        'retry'       => 1,
                    ],
                ],
            ],

            'modules' => [
                'modules' => [
                    'heartbeat' => [
                        'enabled'           => true,
                        'description'       => 'System health monitoring',
                        'role'              => 'heartbeat',
                        'provider_override' => null,
                        'model_override'    => null,
                        'tools'             => [],
                        'cache_policy'      => 'none',
                        'memory_policy'     => 'none',
                        'timeout'           => 10,
                        'retry'             => 1,
                        'prompt_file'       => 'modules/heartbeat.md',
                    ],
                    'reasoning' => [
                        'enabled'           => true,
                        'description'       => 'Deep reasoning and analysis',
                        'role'              => 'reasoning',
                        'provider_override' => null,
                        'model_override'    => null,
                        'tools'             => ['file_read', 'grep_search', 'dir_list'],
                        'cache_policy'      => 'standard',
                        'memory_policy'     => 'full',
                        'timeout'           => 120,
                        'retry'             => 2,
                        'prompt_file'       => 'modules/reasoning.md',
                    ],
                    'coding' => [
                        'enabled'           => true,
                        'description'       => 'Code generation and modification',
                        'role'              => 'coding',
                        'provider_override' => null,
                        'model_override'    => null,
                        'tools'             => ['file_read', 'file_write', 'file_append', 'dir_list', 'mkdir', 'grep_search', 'shell_exec'],
                        'cache_policy'      => 'none',
                        'memory_policy'     => 'full',
                        'timeout'           => 180,
                        'retry'             => 2,
                        'prompt_file'       => 'modules/coding.md',
                    ],
                    'summarizer' => [
                        'enabled'           => true,
                        'description'       => 'Content summarization',
                        'role'              => 'summarization',
                        'provider_override' => null,
                        'model_override'    => null,
                        'tools'             => ['file_read'],
                        'cache_policy'      => 'aggressive',
                        'memory_policy'     => 'summary_only',
                        'timeout'           => 60,
                        'retry'             => 1,
                        'prompt_file'       => 'modules/summarizer.md',
                    ],
                    'memory' => [
                        'enabled'           => true,
                        'description'       => 'Memory management and compaction',
                        'role'              => 'memory_compaction',
                        'provider_override' => null,
                        'model_override'    => null,
                        'tools'             => ['file_read', 'file_write', 'dir_list'],
                        'cache_policy'      => 'none',
                        'memory_policy'     => 'none',
                        'timeout'           => 120,
                        'retry'             => 1,
                        'prompt_file'       => 'modules/memory.md',
                    ],
                    'planner' => [
                        'enabled'           => true,
                        'description'       => 'Task planning and decomposition',
                        'role'              => 'planning',
                        'provider_override' => null,
                        'model_override'    => null,
                        'tools'             => ['file_read', 'dir_list', 'grep_search'],
                        'cache_policy'      => 'standard',
                        'memory_policy'     => 'full',
                        'timeout'           => 120,
                        'retry'             => 2,
                        'prompt_file'       => 'modules/planner.md',
                    ],
                    'browser' => [
                        'enabled'           => true,
                        'description'       => 'Web content fetching and processing',
                        'role'              => 'browser',
                        'provider_override' => null,
                        'model_override'    => null,
                        'tools'             => ['browser_fetch', 'browser_text', 'http_get'],
                        'cache_policy'      => 'standard',
                        'memory_policy'     => 'summary_only',
                        'timeout'           => 60,
                        'retry'             => 1,
                        'prompt_file'       => 'modules/browser.md',
                    ],
                    'tool_router' => [
                        'enabled'           => true,
                        'description'       => 'Routes tool execution requests',
                        'role'              => 'fast_response',
                        'provider_override' => null,
                        'model_override'    => null,
                        'tools'             => ['*'],
                        'cache_policy'      => 'none',
                        'memory_policy'     => 'full',
                        'timeout'           => 30,
                        'retry'             => 1,
                        'prompt_file'       => 'modules/tool_router.md',
                    ],
                ],
            ],

            'tools' => [
                'tools' => [
                    'file_read'    => ['enabled' => true,  'description' => 'Read file contents',                   'timeout' => 10],
                    'file_write'   => ['enabled' => true,  'description' => 'Write content to file',                'timeout' => 10],
                    'file_append'  => ['enabled' => true,  'description' => 'Append content to file',               'timeout' => 10],
                    'dir_list'     => ['enabled' => true,  'description' => 'List directory contents',              'timeout' => 10],
                    'mkdir'        => ['enabled' => true,  'description' => 'Create directory',                     'timeout' => 10],
                    'move_file'    => ['enabled' => true,  'description' => 'Move or rename file',                  'timeout' => 10],
                    'delete_file'  => ['enabled' => true,  'description' => 'Delete a file',                        'timeout' => 10],
                    'grep_search'  => ['enabled' => true,  'description' => 'Search file contents with patterns',   'timeout' => 30],
                    'http_get'     => ['enabled' => true,  'description' => 'Make HTTP GET request',                'timeout' => 30],
                    'browser_fetch' => ['enabled' => true, 'description' => 'Fetch web page content',               'timeout' => 30],
                    'browser_text' => ['enabled' => true,  'description' => 'Extract text from web page',           'timeout' => 30],
                    'shell_exec'   => ['enabled' => true,  'description' => 'Execute shell command',                'timeout' => 60, 'allowed_commands' => []],
                    'system_info'  => ['enabled' => true,  'description' => 'Get system information',               'timeout' => 10],
                ],
            ],

            'service' => [
                'service' => [
                    'enabled'                      => true,
                    'loop_interval_ms'             => 1000,
                    'heartbeat_interval'           => 60,
                    'maintenance_interval'         => 3600,
                    'provider_health_interval'     => 300,
                    'task_check_interval'          => 5,
                    'memory_compaction_interval'   => 3600,
                    'cache_prune_interval'         => 7200,
                    'max_concurrent_tasks'         => 3,
                    'log_file'                     => 'writable/agent/logs/service.log',
                    'pid_file'                     => 'writable/agent/state/service.pid',
                    'state_file'                   => 'writable/agent/state/service.json',
                ],
            ],

            'api' => [
                'enabled' => true,
                'token'   => null,
                'server'  => [
                    'enabled' => true,
                    'host'    => '0.0.0.0',
                    'port'    => 8081,
                ],
                'rate_limit' => [
                    'requests_per_minute' => 30,
                    'enabled'             => false,
                ],
                'cors' => [
                    'allowed_origins' => ['*'],
                    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
                    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept'],
                ],
                'defaults' => [
                    'role'        => 'reasoning',
                    'module'      => 'reasoning',
                    'max_history' => 100,
                ],
            ],
        ];
    }

    public function run(array $params)
    {
        $storage  = new FileStorage();
        $config   = new ConfigLoader($storage);
        $defaults = $this->getDefaults();
        $autoYes  = CLI::getOption('yes') !== null || CLI::getOption('y') !== null;
        $fileOnly = CLI::getOption('file') ?? ($params[0] ?? null);

        // If targeting a single file, validate it exists
        if ($fileOnly) {
            if (!isset($defaults[$fileOnly])) {
                CLI::error("Unknown config file: {$fileOnly}");
                CLI::write('Available: ' . implode(', ', array_keys($defaults)), 'light_gray');
                return;
            }
            $targets = [$fileOnly];
        } else {
            $targets = array_keys($defaults);
        }

        // Show what will be reset
        CLI::write('Config files to reset:', 'yellow');
        foreach ($targets as $name) {
            CLI::write("  - writable/agent/config/{$name}.json", 'white');
        }
        CLI::newLine();

        if (!$autoYes) {
            $confirm = CLI::prompt('This will overwrite current config with shipped defaults. Continue?', ['y', 'n']);
            if (strtolower($confirm) !== 'y') {
                CLI::write('Aborted.', 'light_gray');
                return;
            }
        }

        // Reset each file
        foreach ($targets as $name) {
            $config->save($name, $defaults[$name]);
            CLI::write("  ✓ {$name}.json", 'green');
        }

        // Clear the config cache so subsequent commands pick up fresh values
        $config->reload();

        CLI::newLine();
        CLI::write('Config reset to defaults.', 'green');

        if (!$fileOnly) {
            CLI::write('Run "php spark agent:setup" to reconfigure your providers.', 'light_gray');
        }
    }
}
