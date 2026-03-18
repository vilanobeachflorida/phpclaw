<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Auth\OAuthManager;
use App\Libraries\UI\TerminalUI;

/**
 * Interactive setup wizard for PHPClaw.
 * Pretty terminal UI with arrow-key menus, back navigation, and styled output.
 */
class SetupCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:setup';
    protected $description = 'Run the PHPClaw setup wizard';

    private TerminalUI $ui;

    public function run(array $params)
    {
        $this->ui = new TerminalUI();

        $this->ui->banner('PHPClaw Setup Wizard', 'Terminal-first multi-model AI agent shell');

        $data = $this->ui->wizard([
            ['label' => 'Environment Check',    'key' => 'env',      'callback' => [$this, 'stepEnvironment']],
            ['label' => 'Directory Structure',   'key' => 'dirs',     'callback' => [$this, 'stepDirectories']],
            ['label' => 'Configuration Files',   'key' => 'config',   'callback' => [$this, 'stepConfig']],
            ['label' => 'Provider Setup',        'key' => 'provider', 'callback' => [$this, 'stepProvider']],
            ['label' => 'State Initialization',  'key' => 'state',    'callback' => [$this, 'stepState']],
            ['label' => 'Prompt Templates',      'key' => 'prompts',  'callback' => [$this, 'stepPrompts']],
            ['label' => 'Validation',            'key' => 'validate', 'callback' => [$this, 'stepValidate']],
        ]);

        if ($data['_aborted'] ?? false) {
            $this->ui->newLine();
            $this->ui->warn('Setup aborted.');
            return;
        }

        $this->ui->newLine();
        $this->ui->successBox(
            '  Setup Complete!  ',
            '',
            '  Your PHPClaw agent is ready to go.'
        );
        $this->ui->newLine();

        $this->ui->header('Next Steps');
        $this->ui->bullet('php spark agent:status      Check system status', 'bright_cyan');
        $this->ui->bullet('php spark agent:providers    Check provider connectivity', 'bright_cyan');
        $this->ui->bullet('php spark agent:chat         Start chatting', 'bright_cyan');
        $this->ui->bullet('php spark agent:serve        Start background service', 'bright_cyan');
        $this->ui->newLine();
    }

    // ── Step 1: Environment ─────────────────────────────────────────

    public function stepEnvironment(TerminalUI $ui, array &$data): string
    {
        $checks = [
            ['PHP version >= 8.2',       version_compare(PHP_VERSION, '8.2.0', '>='), true],
            ['curl extension',           extension_loaded('curl'),    true],
            ['json extension',           extension_loaded('json'),    true],
            ['mbstring extension',       extension_loaded('mbstring'), true],
            ['pcntl extension (signals)', extension_loaded('pcntl'),  false], // optional
            ['readline extension',       function_exists('readline'), false], // optional
        ];

        $allRequired = true;
        foreach ($checks as [$label, $ok, $required]) {
            $detail = '';
            if (!$ok && !$required) $detail = 'optional';
            $ui->check($label, $ok, $detail);
            if (!$ok && $required) $allRequired = false;
        }

        $ui->newLine();
        $ui->keyValue([
            'PHP Version' => PHP_VERSION,
            'OS'          => PHP_OS . ' (' . php_uname('m') . ')',
            'SAPI'        => php_sapi_name(),
        ]);

        // Check writable
        $writablePath = WRITEPATH;
        $writable = is_writable($writablePath);
        $ui->newLine();
        $ui->check('Writable directory', $writable, $writablePath);

        if (!$allRequired) {
            $ui->newLine();
            $ui->errorBox('Required extensions are missing. Cannot continue.');
            return 'abort';
        }

        if (!$writable) {
            $ui->errorBox("Directory not writable: {$writablePath}");
            return 'abort';
        }

        $ui->newLine();
        $ui->success('Environment looks good');
        return 'next';
    }

    // ── Step 2: Directories ─────────────────────────────────────────

    public function stepDirectories(TerminalUI $ui, array &$data): string
    {
        $basePath = WRITEPATH . 'agent';
        $dirs = [
            'config', 'sessions', 'tasks',
            'memory/global/compacted', 'memory/global/summaries',
            'memory/sessions', 'memory/modules', 'memory/tasks',
            'cache/llm', 'cache/tools', 'cache/browser', 'cache/providers', 'cache/manifests',
            'logs',
            'prompts/system', 'prompts/roles', 'prompts/modules', 'prompts/tasks',
            'state', 'queues', 'locks',
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

        if ($created > 0) {
            $ui->success("Created {$created} directories");
        }
        if ($existed > 0) {
            $ui->dim("{$existed} directories already existed");
        }

        $ui->dim("Base path: {$basePath}");
        return 'next';
    }

    // ── Step 3: Config Files ────────────────────────────────────────

    public function stepConfig(TerminalUI $ui, array &$data): string
    {
        $storage = new FileStorage();

        $configs = $this->getDefaultConfigs();

        $rows = [];
        $written = 0;
        $skipped = 0;
        foreach ($configs as $name => $configData) {
            $path = "config/{$name}.json";
            if ($storage->exists($path)) {
                $rows[] = [$name . '.json', $ui->style('kept', 'yellow'), $ui->style('already exists', 'gray')];
                $skipped++;
            } else {
                $storage->writeJson($path, $configData);
                $rows[] = [$name . '.json', $ui->style('created', 'bright_green'), $ui->style('default config', 'gray')];
                $written++;
            }
        }

        $ui->table(['File', 'Status', 'Note'], $rows, 'blue');
        $ui->newLine();
        $ui->dim("Written: {$written}, Kept: {$skipped}");
        return 'next';
    }

    // ── Step 4: Provider ────────────────────────────────────────────

    public function stepProvider(TerminalUI $ui, array &$data): string
    {
        $storage = new FileStorage();
        $config = new ConfigLoader($storage);
        $providersConfig = $config->load('providers');

        $choice = $ui->menu('Choose your LLM provider', [
            ['label' => 'LM Studio',          'description' => 'Local inference server'],
            ['label' => 'Ollama',              'description' => 'Local model runner'],
            ['label' => 'ChatGPT OAuth',       'description' => 'Sign in with your OpenAI account'],
            ['label' => 'Claude OAuth',        'description' => 'Sign in with your Claude subscription'],
            ['label' => 'Claude API',          'description' => 'Anthropic API key (pay per token)'],
            ['label' => 'Claude Code CLI',     'description' => 'Local Claude Code runtime'],
            ['label' => 'OpenLLM',             'description' => 'Custom OpenAI-compatible endpoint'],
            ['label' => 'Skip',               'description' => 'Configure manually later'],
        ]);

        if ($choice === null) return 'back';

        $ui->newLine();

        switch ($choice) {
            case 0: $this->configureLMStudio($ui, $storage, $providersConfig); break;
            case 1: $this->configureOllama($ui, $storage, $providersConfig); break;
            case 2: $this->configureChatGPTOAuth($ui, $storage, $providersConfig); break;
            case 3: $this->configureClaudeOAuth($ui, $storage, $providersConfig); break;
            case 4: $this->configureClaudeAPIKey($ui, $storage, $providersConfig); break;
            case 5: $this->configureClaudeCode($ui, $storage, $providersConfig); break;
            case 6: $this->configureOpenLLM($ui, $storage, $providersConfig); break;
            case 7:
                $ui->dim('Skipped. Edit writable/agent/config/providers.json manually.');
                break;
        }

        return 'next';
    }

    // ── Step 5: State ───────────────────────────────────────────────

    public function stepState(TerminalUI $ui, array &$data): string
    {
        $storage = new FileStorage();

        $stateFiles = [
            'state/service.json'        => ['status' => 'stopped', 'started_at' => null, 'last_heartbeat' => null, 'uptime_seconds' => 0, 'tasks_processed' => 0, 'errors' => 0],
            'state/heartbeat.json'      => ['last_check' => null, 'status' => 'unknown', 'providers' => []],
            'state/active-tasks.json'   => ['tasks' => []],
            'state/provider-health.json'=> ['providers' => [], 'last_check' => null],
            'state/loop.json'           => ['iteration' => 0, 'last_tick' => null, 'status' => 'idle'],
            'sessions/index.json'       => ['sessions' => [], 'active_session' => null],
            'tasks/index.json'          => ['tasks' => []],
            'memory/global/index.json'  => ['total_notes' => 0, 'total_summaries' => 0, 'last_compaction' => null, 'compaction_count' => 0],
        ];

        $written = 0;
        $skipped = 0;
        foreach ($stateFiles as $path => $stateData) {
            if ($storage->exists($path)) {
                $skipped++;
            } else {
                $storage->writeJson($path, $stateData);
                $written++;
            }
        }

        if ($written > 0) $ui->success("Initialized {$written} state files");
        if ($skipped > 0) $ui->dim("{$skipped} state files already existed");
        return 'next';
    }

    // ── Step 6: Prompts ─────────────────────────────────────────────

    public function stepPrompts(TerminalUI $ui, array &$data): string
    {
        $storage = new FileStorage();

        $prompts = [
            'prompts/system/default.md'      => "You are PHPClaw, a terminal-native AI agent assistant. You help users with tasks by leveraging available tools and your reasoning capabilities. Be concise, helpful, and action-oriented.\n",
            'prompts/modules/heartbeat.md'   => "You are a system health monitor. Respond with a brief status confirmation. Keep responses under 50 words.\n",
            'prompts/modules/reasoning.md'   => "You are an expert reasoning assistant. Think step-by-step, analyze problems carefully, and provide well-structured responses. When solving problems, break them down into clear logical steps.\n",
            'prompts/modules/coding.md'      => "You are an expert software engineer. Write clean, well-structured code. Follow best practices and conventions. Explain your approach when the solution is non-obvious.\n",
            'prompts/modules/summarizer.md'  => "You are a summarization assistant. Provide concise, accurate summaries that preserve key information. Focus on the most important points and actionable items.\n",
            'prompts/modules/memory.md'      => "You are a memory management assistant. Analyze conversation logs and extract key facts, decisions, and actionable information. Create concise summaries that preserve meaning while reducing size.\n",
            'prompts/modules/planner.md'     => "You are a task planning assistant. Break down complex tasks into clear, actionable steps. Consider dependencies, risks, and optimal execution order.\n",
            'prompts/modules/browser.md'     => "You are a web content processing assistant. Analyze fetched web content and extract relevant information. Summarize key points from web pages.\n",
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

        if ($written > 0) $ui->success("Created {$written} prompt templates");
        if ($skipped > 0) $ui->dim("{$skipped} prompt files already existed");
        return 'next';
    }

    // ── Step 7: Validate ────────────────────────────────────────────

    public function stepValidate(TerminalUI $ui, array &$data): string
    {
        $storage = new FileStorage();
        $errors = [];

        // Config files
        $requiredConfigs = ['config/app.json', 'config/providers.json', 'config/roles.json', 'config/modules.json', 'config/tools.json', 'config/service.json'];
        foreach ($requiredConfigs as $file) {
            if (!$storage->exists($file)) {
                $errors[] = "Missing config: {$file}";
            }
        }

        // State files
        $requiredState = ['state/service.json', 'sessions/index.json', 'tasks/index.json'];
        foreach ($requiredState as $file) {
            if (!$storage->exists($file)) {
                $errors[] = "Missing state: {$file}";
            }
        }

        // Providers
        $config = new ConfigLoader($storage);
        $providers = $config->get('providers', 'providers', []);
        $enabledCount = 0;
        $enabledNames = [];
        foreach ($providers as $name => $p) {
            if ($p['enabled'] ?? false) {
                $enabledCount++;
                $enabledNames[] = $name;
            }
        }
        if ($enabledCount === 0) {
            $errors[] = 'No providers enabled';
        }

        // Directories
        $requiredDirs = ['logs', 'cache', 'memory', 'sessions', 'tasks', 'state', 'config', 'prompts'];
        foreach ($requiredDirs as $dir) {
            if (!is_dir($storage->path($dir))) {
                $errors[] = "Missing directory: {$dir}";
            }
        }

        // Display results
        if (empty($errors)) {
            $ui->check('Config files', true, count($requiredConfigs) . ' files');
            $ui->check('State files', true, count($requiredState) . ' files');
            $ui->check('Providers', true, implode(', ', $enabledNames));
            $ui->check('Directories', true, count($requiredDirs) . ' directories');
            $ui->newLine();
            $ui->success('All checks passed');
        } else {
            foreach ($errors as $err) {
                $ui->check($err, false);
            }
            $ui->newLine();
            $ui->warn(count($errors) . ' issue(s) found — check the errors above');
        }

        $ui->newLine();
        $ui->keyValue([
            'Storage'    => $storage->getBasePath(),
            'Providers'  => $enabledCount . ' enabled',
            'Configs'    => count($requiredConfigs) . ' files',
        ]);

        return 'next';
    }

    // ── Provider Configuration Methods ──────────────────────────────

    private function configureLMStudio(TerminalUI $ui, FileStorage $storage, array $providersConfig): void
    {
        $baseUrl = $ui->prompt('LM Studio URL', 'http://localhost:1234');
        if ($baseUrl === 'back') return;
        $model = $ui->prompt('Default model (or "default" for loaded model)', 'default');

        $providersConfig['providers']['lmstudio'] = [
            'enabled' => true, 'type' => 'lmstudio',
            'description' => 'LM Studio local server',
            'base_url' => $baseUrl, 'default_model' => $model,
            'timeout' => 120, 'retry' => 2, 'options' => [],
        ];
        $storage->writeJson('config/providers.json', $providersConfig);
        $this->updateDefaultProvider($storage, 'lmstudio', $model);

        $this->testConnection($ui, $baseUrl . '/v1/models', 'LM Studio', function($data) use ($ui) {
            $models = $data['data'] ?? [];
            if (!empty($models)) {
                $ui->divider('Loaded models');
                foreach ($models as $m) {
                    $ui->bullet($m['id'] ?? 'unknown', 'white');
                }
            }
        });
    }

    private function configureOllama(TerminalUI $ui, FileStorage $storage, array $providersConfig): void
    {
        $baseUrl = $ui->prompt('Ollama URL', 'http://localhost:11434');
        if ($baseUrl === 'back') return;
        $model = $ui->prompt('Default model', 'llama3');

        $providersConfig['providers']['ollama']['enabled'] = true;
        $providersConfig['providers']['ollama']['base_url'] = $baseUrl;
        $providersConfig['providers']['ollama']['default_model'] = $model;
        $storage->writeJson('config/providers.json', $providersConfig);
        $this->updateDefaultProvider($storage, 'ollama', $model);

        $this->testConnection($ui, $baseUrl . '/api/tags', 'Ollama', function($data) use ($ui) {
            $models = $data['models'] ?? [];
            if (!empty($models)) {
                $ui->divider('Available models');
                foreach (array_slice($models, 0, 10) as $m) {
                    $ui->bullet($m['name'], 'white');
                }
                if (count($models) > 10) {
                    $ui->dim('... and ' . (count($models) - 10) . ' more');
                }
            }
        });
    }

    /**
     * ChatGPT OAuth — browser sign-in with OpenAI account.
     *
     * Requires an OAuth client ID. Checks env var first, then asks.
     * Opens browser to OpenAI's auth page, catches the redirect.
     */
    private function configureChatGPTOAuth(TerminalUI $ui, FileStorage $storage, array $providersConfig): void
    {
        $model = 'gpt-4o';
        $oauth = new OAuthManager($storage);

        // Resolve client ID from env or config
        $oauthConfig = $oauth->resolveOAuthConfig('chatgpt', $providersConfig['providers']['chatgpt']['oauth'] ?? []);

        // If no client ID available, ask for one
        if (!$oauthConfig) {
            $ui->infoBox(
                'ChatGPT OAuth requires an OAuth Client ID.',
                'Register an app at: https://platform.openai.com',
                'Or set PHPCLAW_OPENAI_CLIENT_ID in your environment.'
            );
            $clientId = $ui->prompt('OAuth Client ID');
            if (!$clientId || $clientId === 'back') return;
            $oauthConfig = ['enabled' => true, 'client_id' => $clientId, 'client_secret' => ''];
        }

        $ui->info('Signing in to OpenAI / ChatGPT...');
        $ui->dim("Default model: {$model}");

        // Enable provider
        $providersConfig['providers']['chatgpt']['enabled'] = true;
        $providersConfig['providers']['chatgpt']['default_model'] = $model;
        $providersConfig['providers']['chatgpt']['oauth'] = $oauthConfig;
        $storage->writeJson('config/providers.json', $providersConfig);
        $this->updateDefaultProvider($storage, 'chatgpt', $model);

        // Go straight to browser login
        $this->runOAuthBrowserFlow($ui, $storage, 'chatgpt', $oauthConfig);
    }

    /**
     * Claude OAuth — setup-token flow.
     *
     * Claude doesn't use browser OAuth like ChatGPT.
     * Instead, you run `claude setup-token` and paste the token.
     * This uses your Claude Pro/Max subscription.
     */
    private function configureClaudeOAuth(TerminalUI $ui, FileStorage $storage, array $providersConfig): void
    {
        $model = 'claude-sonnet-4-20250514';
        $oauth = new OAuthManager($storage);

        $ui->infoBox(
            'Claude OAuth uses a setup token from Claude Code CLI.',
            'This uses your Claude Pro or Max subscription.',
            '',
            'To get your token, run this in another terminal:',
            '  claude setup-token',
        );

        $ui->newLine();
        $token = $ui->prompt('Paste your setup token', '', true);
        if (!$token || $token === 'back') return;

        // Store the token
        $oauth->storeSetupToken('claude_api', $token);

        // Enable provider with OAuth
        $providersConfig['providers']['claude_api']['enabled'] = true;
        $providersConfig['providers']['claude_api']['default_model'] = $model;
        $providersConfig['providers']['claude_api']['oauth'] = ['enabled' => true];
        $storage->writeJson('config/providers.json', $providersConfig);
        $this->updateDefaultProvider($storage, 'claude_api', $model);

        $ui->newLine();
        $info = $oauth->getTokenInfo('claude_api');
        if ($info) {
            $ui->successBox(
                'Claude subscription auth configured!',
                '',
                'Token: ' . ($info['token_preview'] ?? '****'),
                "Model: {$model}",
            );
        } else {
            $ui->success('Claude configured with setup token');
        }
    }

    /**
     * Claude API key — pay-per-token via Anthropic Console.
     */
    private function configureClaudeAPIKey(TerminalUI $ui, FileStorage $storage, array $providersConfig): void
    {
        $ui->infoBox(
            'Claude API uses pay-per-token billing.',
            'Get your API key at: https://console.anthropic.com/settings/keys'
        );

        $ui->newLine();
        $apiKey = $ui->prompt('Anthropic API key', '', true);
        if (!$apiKey || $apiKey === 'back') return;
        $model = $ui->prompt('Default model', 'claude-sonnet-4-20250514');

        if (str_starts_with($apiKey, 'sk-ant-')) {
            $ui->newLine();
            $ui->warnBox(
                'Tip: store API keys in .env, not config files',
                'Add to .env: ANTHROPIC_API_KEY=' . substr($apiKey, 0, 12) . '...'
            );
        }

        $providersConfig['providers']['claude_api']['api_key_env'] = 'ANTHROPIC_API_KEY';
        $providersConfig['providers']['claude_api']['enabled'] = true;
        $providersConfig['providers']['claude_api']['default_model'] = $model;
        $storage->writeJson('config/providers.json', $providersConfig);
        $this->updateDefaultProvider($storage, 'claude_api', $model);
        $ui->success('Claude API configured');
    }

    private function configureClaudeCode(TerminalUI $ui, FileStorage $storage, array $providersConfig): void
    {
        $command = $ui->prompt('Claude CLI command', 'claude');
        if ($command === 'back') return;

        $output = shell_exec("which {$command} 2>/dev/null") ?? shell_exec("where {$command} 2>/dev/null");
        if (empty(trim($output ?? ''))) {
            $ui->warn("'{$command}' not found in PATH");
            $ui->dim('Install Claude Code CLI first');
        } else {
            $ui->check("Found: " . trim($output), true);
        }

        $providersConfig['providers']['claude_code']['enabled'] = true;
        $providersConfig['providers']['claude_code']['command'] = $command;
        $storage->writeJson('config/providers.json', $providersConfig);
        $this->updateDefaultProvider($storage, 'claude_code', 'default');
        $ui->success('Claude Code configured');
    }

    private function configureOpenLLM(TerminalUI $ui, FileStorage $storage, array $providersConfig): void
    {
        $baseUrl = $ui->prompt('Endpoint base URL', 'http://localhost:8000');
        if ($baseUrl === 'back') return;
        $model = $ui->prompt('Default model name', 'default');
        $apiKey = $ui->prompt('API key env var (or leave blank)', 'OPENLLM_API_KEY');

        $providersConfig['providers']['openllm']['enabled'] = true;
        $providersConfig['providers']['openllm']['base_url'] = $baseUrl;
        $providersConfig['providers']['openllm']['default_model'] = $model;
        $providersConfig['providers']['openllm']['api_key_env'] = $apiKey;
        $storage->writeJson('config/providers.json', $providersConfig);
        $this->updateDefaultProvider($storage, 'openllm', $model);
        $ui->success('OpenLLM endpoint configured');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Run a full OAuth browser login flow inline in the setup wizard.
     *
     * Shows the auth URL, tries the callback server, and falls back to
     * letting the user paste the redirect URL if the server times out.
     */
    private function runOAuthBrowserFlow(TerminalUI $ui, FileStorage $storage, string $provider, array $oauthConfig): void
    {
        $oauth = new OAuthManager($storage);

        $ui->newLine();
        $ui->divider('Browser Login', 'bright_cyan');
        $ui->newLine();

        $result = $oauth->browserLogin($provider, $oauthConfig, [
            'showUrl' => function (string $authUrl) use ($ui) {
                $ui->info('Open this URL in your browser to authorize:');
                $ui->newLine();

                // Show URL in a box so it's easy to copy
                $ui->box([$authUrl], 'bright_cyan');

                $ui->newLine();
                $ui->dim('(Attempting to open your browser automatically...)');
            },

            'onWaiting' => function () use ($ui) {
                $ui->newLine();
                $ui->inline($ui->style('  ◆', 'bright_magenta'));
                $ui->write(' Waiting for authorization... (complete login in your browser)', 'gray');
                $ui->dim('  The wizard will continue automatically when you authorize.');
                $ui->dim('  If nothing happens, you can paste the redirect URL below.');
                $ui->newLine();
            },

            'promptPaste' => function () use ($ui): ?string {
                $ui->newLine();
                $ui->warn('Callback server timed out or could not receive the redirect.');
                $ui->newLine();
                $ui->info('After authorizing in your browser, copy the URL from your');
                $ui->info('browser\'s address bar and paste it here.');
                $ui->dim('It looks like: http://localhost:8484/callback?code=abc123&state=xyz...');
                $ui->newLine();

                $url = $ui->prompt('Paste redirect URL (or press Enter to skip)');
                if (!$url || $url === 'back') return null;
                return $url;
            },

            'onExchanging' => function () use ($ui) {
                $ui->inline($ui->style('  ◆', 'bright_magenta'));
                $ui->write(' Exchanging authorization code for token...', 'gray');
            },
        ]);

        $ui->newLine();

        if ($result['success'] ?? false) {
            $info = $result['token_info'] ?? [];
            $ui->successBox(
                "Logged in to {$provider} successfully!",
                '',
                'Token: ' . ($info['token_preview'] ?? '****'),
                'Refresh token: ' . ($info['has_refresh_token'] ? 'yes' : 'no'),
                $info['expires_at'] ? ('Expires: ' . $info['expires_at']) : 'No expiration',
            );
        } else {
            $error = $result['error'] ?? 'Unknown error';
            $ui->errorBox(
                "OAuth login failed: {$error}",
                '',
                "You can try again later: php spark agent:auth login {$provider}",
            );
        }
    }

    private function testConnection(TerminalUI $ui, string $url, string $name, callable $onSuccess): void
    {
        $ui->newLine();
        $result = $ui->spinner("Testing {$name} connection", function() use ($url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
            $result = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err) throw new \RuntimeException($err);
            return json_decode($result, true);
        });

        if ($result) {
            $onSuccess($result);
        }
    }

    private function updateDefaultProvider(FileStorage $storage, string $provider, string $model): void
    {
        $appConfig = $storage->readJson('config/app.json') ?? [];
        $appConfig['default_provider'] = $provider;
        $appConfig['default_model'] = $model;
        $storage->writeJson('config/app.json', $appConfig);

        $rolesConfig = $storage->readJson('config/roles.json') ?? ['roles' => []];
        foreach ($rolesConfig['roles'] as $name => &$role) {
            $role['provider'] = $provider;
            $role['model'] = $model;
        }
        $storage->writeJson('config/roles.json', $rolesConfig);
    }

    private function getDefaultConfigs(): array
    {
        return [
            'app' => [
                'name' => 'PHPClaw', 'version' => '0.1.0',
                'description' => 'Terminal-first multi-model AI agent shell',
                'debug' => false, 'verbose' => false, 'timezone' => 'UTC',
                'storage_path' => 'writable/agent',
                'default_provider' => 'ollama', 'default_model' => 'llama3',
                'default_role' => 'reasoning', 'default_module' => 'reasoning',
                'session' => ['auto_save' => true, 'max_transcript_lines' => 10000, 'default_name_prefix' => 'session'],
                'memory' => ['enabled' => true, 'compaction_interval' => 3600, 'max_notes_before_compaction' => 500, 'summary_max_length' => 2000],
                'cache' => ['enabled' => true, 'default_ttl' => 3600, 'max_size_mb' => 500],
            ],
            'roles' => [
                'roles' => [
                    'heartbeat'        => ['description' => 'Lightweight health check role',          'provider' => 'ollama', 'model' => 'llama3', 'fallback' => [],           'timeout' => 10,  'retry' => 1],
                    'reasoning'        => ['description' => 'Deep reasoning and analysis',            'provider' => 'ollama', 'model' => 'llama3', 'fallback' => ['openllm'],  'timeout' => 120, 'retry' => 2],
                    'coding'           => ['description' => 'Code generation and analysis',           'provider' => 'claude_code', 'model' => 'default', 'fallback' => ['ollama'], 'timeout' => 180, 'retry' => 2],
                    'summarization'    => ['description' => 'Fast summarization tasks',               'provider' => 'ollama', 'model' => 'llama3', 'fallback' => [],           'timeout' => 60,  'retry' => 1],
                    'planning'         => ['description' => 'Task planning and decomposition',        'provider' => 'ollama', 'model' => 'llama3', 'fallback' => [],           'timeout' => 120, 'retry' => 2],
                    'browser'          => ['description' => 'Web content processing',                 'provider' => 'ollama', 'model' => 'llama3', 'fallback' => [],           'timeout' => 60,  'retry' => 1],
                    'memory_compaction'=> ['description' => 'Memory compaction and summarization',    'provider' => 'ollama', 'model' => 'llama3', 'fallback' => [],           'timeout' => 120, 'retry' => 1],
                    'fast_response'    => ['description' => 'Quick responses for simple queries',     'provider' => 'ollama', 'model' => 'llama3', 'fallback' => [],           'timeout' => 30,  'retry' => 1],
                ],
            ],
            'modules' => [
                'modules' => [
                    'heartbeat'   => ['enabled' => true, 'description' => 'System health monitoring',       'role' => 'heartbeat',        'provider_override' => null, 'model_override' => null, 'tools' => [],                                                                             'cache_policy' => 'none',       'memory_policy' => 'none',         'timeout' => 10,  'retry' => 1, 'prompt_file' => 'modules/heartbeat.md'],
                    'reasoning'   => ['enabled' => true, 'description' => 'Deep reasoning and analysis',    'role' => 'reasoning',        'provider_override' => null, 'model_override' => null, 'tools' => ['file_read', 'grep_search', 'dir_list'],                                       'cache_policy' => 'standard',   'memory_policy' => 'full',         'timeout' => 120, 'retry' => 2, 'prompt_file' => 'modules/reasoning.md'],
                    'coding'      => ['enabled' => true, 'description' => 'Code generation and modification','role' => 'coding',          'provider_override' => null, 'model_override' => null, 'tools' => ['file_read', 'file_write', 'file_append', 'dir_list', 'mkdir', 'grep_search', 'shell_exec'], 'cache_policy' => 'none', 'memory_policy' => 'full', 'timeout' => 180, 'retry' => 2, 'prompt_file' => 'modules/coding.md'],
                    'summarizer'  => ['enabled' => true, 'description' => 'Content summarization',          'role' => 'summarization',    'provider_override' => null, 'model_override' => null, 'tools' => ['file_read'],                                                                  'cache_policy' => 'aggressive', 'memory_policy' => 'summary_only', 'timeout' => 60,  'retry' => 1, 'prompt_file' => 'modules/summarizer.md'],
                    'memory'      => ['enabled' => true, 'description' => 'Memory management and compaction','role' => 'memory_compaction','provider_override' => null, 'model_override' => null, 'tools' => ['file_read', 'file_write', 'dir_list'],                                       'cache_policy' => 'none',       'memory_policy' => 'none',         'timeout' => 120, 'retry' => 1, 'prompt_file' => 'modules/memory.md'],
                    'planner'     => ['enabled' => true, 'description' => 'Task planning and decomposition','role' => 'planning',         'provider_override' => null, 'model_override' => null, 'tools' => ['file_read', 'dir_list', 'grep_search'],                                      'cache_policy' => 'standard',   'memory_policy' => 'full',         'timeout' => 120, 'retry' => 2, 'prompt_file' => 'modules/planner.md'],
                    'browser'     => ['enabled' => true, 'description' => 'Web content fetching',           'role' => 'browser',          'provider_override' => null, 'model_override' => null, 'tools' => ['browser_fetch', 'browser_text', 'http_get'],                                  'cache_policy' => 'standard',   'memory_policy' => 'summary_only', 'timeout' => 60,  'retry' => 1, 'prompt_file' => 'modules/browser.md'],
                    'tool_router' => ['enabled' => true, 'description' => 'Routes tool execution requests', 'role' => 'fast_response',    'provider_override' => null, 'model_override' => null, 'tools' => ['*'],                                                                         'cache_policy' => 'none',       'memory_policy' => 'full',         'timeout' => 30,  'retry' => 1, 'prompt_file' => 'modules/tool_router.md'],
                ],
            ],
            'providers' => [
                'providers' => [
                    'lmstudio'   => ['enabled' => false, 'type' => 'lmstudio',   'description' => 'LM Studio local server',           'base_url' => 'http://localhost:1234',    'default_model' => 'default',              'timeout' => 120, 'retry' => 2, 'options' => []],
                    'ollama'     => ['enabled' => false, 'type' => 'ollama',     'description' => 'Local Ollama instance',             'base_url' => 'http://localhost:11434',   'default_model' => 'llama3',               'timeout' => 120, 'retry' => 2, 'options' => []],
                    'openllm'    => ['enabled' => false, 'type' => 'openllm',    'description' => 'OpenAI-compatible LLM endpoint',    'base_url' => 'http://localhost:8000',    'api_key_env' => 'OPENLLM_API_KEY',        'default_model' => 'default', 'timeout' => 120, 'retry' => 2, 'headers' => [], 'options' => []],
                    'claude_code'=> ['enabled' => false, 'type' => 'claude_code','description' => 'Claude Code local CLI runtime',     'command' => 'claude',                    'timeout' => 180, 'retry' => 1, 'options' => []],
                    'chatgpt'    => ['enabled' => false, 'type' => 'chatgpt',    'description' => 'ChatGPT via OpenAI API',            'base_url' => 'https://api.openai.com/v1','api_key_env' => 'OPENAI_API_KEY',         'default_model' => 'gpt-4',   'timeout' => 120, 'retry' => 2, 'options' => [], 'oauth' => ['enabled' => false, 'client_id' => '', 'client_secret' => '']],
                    'claude_api' => ['enabled' => false, 'type' => 'claude_api', 'description' => 'Claude via Anthropic API',          'base_url' => 'https://api.anthropic.com','api_key_env' => 'ANTHROPIC_API_KEY',      'default_model' => 'claude-sonnet-4-20250514', 'api_version' => '2023-06-01', 'max_tokens' => 4096, 'timeout' => 180, 'retry' => 2, 'options' => [], 'oauth' => ['enabled' => false, 'client_id' => '', 'client_secret' => '']],
                ],
            ],
            'tools' => [
                'tools' => [
                    'file_read'     => ['enabled' => true, 'description' => 'Read file contents',                'timeout' => 10],
                    'file_write'    => ['enabled' => true, 'description' => 'Write content to file',             'timeout' => 10],
                    'file_append'   => ['enabled' => true, 'description' => 'Append content to file',            'timeout' => 10],
                    'dir_list'      => ['enabled' => true, 'description' => 'List directory contents',           'timeout' => 10],
                    'mkdir'         => ['enabled' => true, 'description' => 'Create directory',                  'timeout' => 10],
                    'move_file'     => ['enabled' => true, 'description' => 'Move or rename file',               'timeout' => 10],
                    'delete_file'   => ['enabled' => true, 'description' => 'Delete a file',                     'timeout' => 10],
                    'grep_search'   => ['enabled' => true, 'description' => 'Search file contents with patterns','timeout' => 30],
                    'http_get'      => ['enabled' => true, 'description' => 'Make HTTP GET request',             'timeout' => 30],
                    'browser_fetch' => ['enabled' => true, 'description' => 'Fetch web page content',            'timeout' => 30],
                    'browser_text'  => ['enabled' => true, 'description' => 'Extract text from web page',        'timeout' => 30],
                    'shell_exec'    => ['enabled' => true, 'description' => 'Execute shell command',             'timeout' => 60],
                    'system_info'   => ['enabled' => true, 'description' => 'Get system information',            'timeout' => 10],
                ],
            ],
            'service' => [
                'service' => [
                    'enabled' => true, 'loop_interval_ms' => 1000, 'heartbeat_interval' => 60,
                    'maintenance_interval' => 3600, 'provider_health_interval' => 300,
                    'task_check_interval' => 5, 'memory_compaction_interval' => 3600,
                    'cache_prune_interval' => 7200, 'max_concurrent_tasks' => 3,
                    'log_file' => 'writable/agent/logs/service.log',
                    'pid_file' => 'writable/agent/state/service.pid',
                    'state_file' => 'writable/agent/state/service.json',
                ],
            ],
        ];
    }
}
