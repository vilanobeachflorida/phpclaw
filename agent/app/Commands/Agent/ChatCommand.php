<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Session\SessionManager;
use App\Libraries\Service\ProviderManager;
use App\Libraries\Service\ToolRegistry;
use App\Libraries\Router\ModelRouter;
use App\Libraries\Memory\MemoryManager;
use App\Libraries\Agent\AgentExecutor;
use App\Libraries\Agent\PromptBuilder;
use App\Libraries\Agent\UsageTracker;
use App\Libraries\UI\TerminalUI;

/**
 * Interactive chat REPL - the primary user interface for PHPClaw.
 *
 * Uses TerminalUI for proper readline input (no backspace issues),
 * styled output, and a clean visual design. Tracks and displays
 * token usage and cost estimates after each turn, like Claude Code.
 */
class ChatCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:chat';
    protected $description = 'Start interactive agent chat session';
    protected $usage = 'agent:chat [session_name]';

    private TerminalUI $ui;
    private FileStorage $storage;
    private ConfigLoader $config;
    private SessionManager $sessions;
    private ProviderManager $providers;
    private ToolRegistry $tools;
    private ModelRouter $router;
    private MemoryManager $memory;
    private AgentExecutor $agent;
    private PromptBuilder $promptBuilder;
    private UsageTracker $usageTracker;
    private string $currentRole = 'reasoning';
    private string $currentModule = 'reasoning';
    private bool $debugMode = false;

    public function run(array $params)
    {
        $this->ui = new TerminalUI();
        $this->storage = new FileStorage();
        $this->config = new ConfigLoader($this->storage);
        $this->sessions = new SessionManager($this->storage);
        $this->providers = new ProviderManager($this->config);
        $this->tools = new ToolRegistry($this->config);
        $this->router = new ModelRouter($this->config);
        $this->memory = new MemoryManager($this->storage);
        $this->usageTracker = new UsageTracker();

        // Load providers and tools
        $this->providers->loadAll();
        $this->tools->loadAll();

        // Register providers with router
        foreach ($this->providers->all() as $name => $provider) {
            $this->router->registerProvider($name, $provider);
        }

        // Build prompt builder
        $this->promptBuilder = new PromptBuilder($this->tools, $this->storage);

        // Create or resume session
        $sessionId = $this->initSession($params[0] ?? null);

        $this->currentRole = $this->config->get('app', 'default_role', 'reasoning');
        $this->currentModule = $this->config->get('app', 'default_module', 'reasoning');

        // Create agent executor with usage tracking
        $this->agent = new AgentExecutor(
            $this->router,
            $this->tools,
            $this->debugMode,
            $this->sessions,
            $sessionId,
            $this->usageTracker
        );

        $this->printBanner();
        $conversationHistory = [];

        // REPL loop
        while (true) {
            $route = $this->router->resolveRole($this->currentRole);
            $input = $this->ui->chatPrompt($this->currentModule, $route['provider']);

            if ($input === null) break;
            $input = trim($input);
            if ($input === '') continue;

            // Handle slash commands
            if (str_starts_with($input, '/')) {
                $handled = $this->handleSlashCommand($input, $sessionId);
                if ($handled === 'exit') break;
                continue;
            }

            // Log user message
            $this->sessions->appendTranscript($sessionId, [
                'event_type' => 'user_message',
                'role' => 'user',
                'content' => $input,
                'provider' => $route['provider'],
                'model' => $route['model'],
                'module' => $this->currentModule,
            ]);

            $conversationHistory[] = ['role' => 'user', 'content' => $input];

            // Build system prompt
            $systemPrompt = $this->promptBuilder->buildSystemPrompt($this->currentModule);

            // Execute agent loop — returns {text, usage}
            // The executor shows its own thinking/working indicators
            $this->ui->newLine();
            $result = $this->agent->execute($this->currentRole, $conversationHistory, $systemPrompt);
            $responseText = $result['text'] ?? '';
            $turnUsage = $result['usage'] ?? null;

            // Show response
            if ($responseText) {
                $this->ui->agentResponse($responseText);
            }

            // Show turn usage line
            if ($turnUsage) {
                $summary = $this->usageTracker->formatTurnSummary($turnUsage);
                $this->ui->turnUsage($summary);
            }

            if ($this->debugMode) {
                $this->ui->dim("[Debug] Role: {$this->currentRole} | Provider: {$route['provider']} | Model: {$route['model']}");
                $this->ui->dim("[Debug] History: " . count($conversationHistory) . " messages");
                $this->ui->dim("[Debug] Session: " . $this->usageTracker->formatSessionSummary());
            }
        }

        // Show final session summary on exit
        $this->ui->newLine();
        $this->ui->hr('bright_blue');
        $sessionSummary = $this->usageTracker->formatSessionSummary();
        if ($sessionSummary) {
            $this->ui->dim("Session: {$sessionSummary}");
        }
        $this->ui->success('Session saved. Goodbye!');
        $this->ui->newLine();
    }

    private function initSession(?string $sessionName): string
    {
        if ($sessionName) {
            $sessions = $this->sessions->list();
            foreach ($sessions as $s) {
                if ($s['name'] === $sessionName || $s['id'] === $sessionName) {
                    $session = $this->sessions->resume($s['id']);
                    $this->ui->info("Resumed session: {$session['name']}");
                    return $session['id'];
                }
            }
            $session = $this->sessions->create($sessionName);
            $this->ui->info("Created session: {$session['name']}");
            return $session['id'];
        }

        $session = $this->sessions->create();
        return $session['id'];
    }

    private function handleSlashCommand(string $input, string $sessionId): ?string
    {
        $parts = explode(' ', $input, 2);
        $cmd = strtolower($parts[0]);
        $arg = $parts[1] ?? '';

        switch ($cmd) {
            case '/help':
                $this->printHelp();
                break;
            case '/exit':
            case '/quit':
                return 'exit';
            case '/usage':
            case '/tokens':
            case '/cost':
                $this->showUsage();
                break;
            case '/provider':
                $this->showProviders();
                break;
            case '/model':
                $route = $this->router->resolveRole($this->currentRole);
                $this->ui->keyValue([
                    'Provider' => $route['provider'],
                    'Model'    => $route['model'],
                    'Role'     => $this->currentRole,
                ]);
                break;
            case '/role':
                if ($arg) {
                    $this->currentRole = $arg;
                    $this->ui->success("Role set to: {$arg}");
                } else {
                    $this->ui->info("Current role: {$this->currentRole}");
                }
                break;
            case '/module':
                if ($arg) {
                    $this->currentModule = $arg;
                    $this->ui->success("Module set to: {$arg}");
                } else {
                    $this->ui->info("Current module: {$this->currentModule}");
                }
                break;
            case '/tools':
                $this->showTools();
                break;
            case '/tasks':
                $this->showTasks();
                break;
            case '/memory':
                $this->showMemory();
                break;
            case '/status':
                $this->showStatus();
                break;
            case '/debug':
                $this->debugMode = !$this->debugMode;
                $this->agent->setDebug($this->debugMode);
                $status = $this->debugMode ? 'ON' : 'OFF';
                $this->ui->info("Debug mode: {$status}");
                break;
            case '/save':
                $this->ui->success("Session auto-saved: {$sessionId}");
                break;
            default:
                $this->ui->warn("Unknown command: {$cmd}. Type /help for help.");
        }
        return null;
    }

    private function printBanner(): void
    {
        $toolCount = count($this->tools->all());
        $enabledProviders = count($this->providers->listEnabled());

        $this->ui->banner('PHPClaw Agent Shell', 'v0.1.0');

        $this->ui->keyValue([
            'Tools'     => "{$toolCount} loaded",
            'Providers' => "{$enabledProviders} active",
            'Module'    => $this->currentModule,
        ], 'bright_cyan');

        $this->ui->newLine();
        $this->ui->dim('Type /help for commands, /usage for token stats, /exit to quit');
        $this->ui->hr();
    }

    private function printHelp(): void
    {
        $this->ui->slashHelp([
            '/help'       => 'Show this help',
            '/exit'       => 'Exit chat',
            '/usage'      => 'Show token usage and cost breakdown',
            '/provider'   => 'Show active providers',
            '/model'      => 'Show current model routing',
            '/role [r]'   => 'Show or set current role',
            '/module [m]' => 'Show or set current module',
            '/tools'      => 'List available tools',
            '/tasks'      => 'Show active tasks',
            '/memory'     => 'Show memory stats',
            '/status'     => 'Show system status',
            '/debug'      => 'Toggle debug mode (shows tokens per request)',
            '/save'       => 'Confirm session save',
        ]);
    }

    private function showUsage(): void
    {
        $summary = $this->usageTracker->getSessionSummary();

        // Format costs for display
        $summary['cost_formatted'] = $this->usageTracker->formatCost($summary['cost']);
        $summary['elapsed_formatted'] = $this->usageTracker->formatDuration((int)($summary['elapsed_s'] * 1000));

        // Format per-model costs
        $perModel = $summary['per_model'] ?? [];
        foreach ($perModel as $model => &$data) {
            $data['cost_formatted'] = $this->usageTracker->formatCost($data['cost']);
        }

        $this->ui->usagePanel($summary, $perModel);

        // Show last turn details
        $lastTurn = $this->usageTracker->getLastTurn();
        if ($lastTurn) {
            $this->ui->divider('Last Turn', 'gray');
            $this->ui->dim($this->usageTracker->formatTurnSummary($lastTurn));
            $this->ui->newLine();
        }
    }

    private function showProviders(): void
    {
        $rows = [];
        foreach ($this->providers->listEnabled() as $p) {
            $rows[] = [
                $this->ui->style($p['name'], 'bright_cyan'),
                $p['description'] ?? '',
            ];
        }
        $this->ui->table(['Provider', 'Description'], $rows, 'blue');
    }

    private function showTools(): void
    {
        $rows = [];
        foreach ($this->tools->listAll() as $t) {
            $status = ($t['enabled'] ?? false)
                ? $this->ui->style('ON', 'bright_green')
                : $this->ui->style('OFF', 'red');
            $rows[] = [$status, $t['name'], $t['description'] ?? ''];
        }
        $this->ui->table(['', 'Tool', 'Description'], $rows, 'blue');
    }

    private function showTasks(): void
    {
        $tasks = (new \App\Libraries\Tasks\TaskManager($this->storage))->list();
        if (empty($tasks)) {
            $this->ui->dim('No active tasks');
            return;
        }
        $rows = [];
        foreach ($tasks as $t) {
            $statusColor = match($t['status'] ?? '') {
                'running'  => 'bright_green',
                'pending'  => 'yellow',
                'failed'   => 'red',
                'complete' => 'green',
                default    => 'gray',
            };
            $rows[] = [
                $this->ui->style($t['status'] ?? '?', $statusColor),
                $t['id'] ?? '',
                $t['title'] ?? '',
            ];
        }
        $this->ui->table(['Status', 'ID', 'Title'], $rows, 'blue');
    }

    private function showMemory(): void
    {
        $stats = $this->memory->getStats();
        $this->ui->keyValue([
            'Global notes' => $stats['global_notes'] ?? 0,
            'Summaries'    => $stats['total_summaries'] ?? 0,
            'Compactions'  => $stats['compaction_count'] ?? 0,
            'Last compact' => $stats['last_compaction'] ?? 'never',
        ]);
    }

    private function showStatus(): void
    {
        $state = $this->storage->readJson('state/service.json') ?? [];
        $heartbeat = $this->storage->readJson('state/heartbeat.json') ?? [];

        $serviceStatus = $state['status'] ?? 'unknown';
        $statusColor = match($serviceStatus) {
            'running' => 'bright_green',
            'stopped' => 'yellow',
            default   => 'gray',
        };

        // Show system status + current session usage inline
        $sessionSummary = $this->usageTracker->formatSessionSummary();

        $this->ui->keyValue([
            'Service'        => $this->ui->style($serviceStatus, $statusColor),
            'Last heartbeat' => $heartbeat['last_check'] ?? 'never',
            'Session usage'  => $sessionSummary ?: 'none yet',
        ]);
    }
}
