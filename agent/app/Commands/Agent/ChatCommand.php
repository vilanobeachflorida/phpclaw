<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Session\SessionManager;
use App\Libraries\Service\ProviderManager;
use App\Libraries\Service\ToolRegistry;
use App\Libraries\Router\ModelRouter;
use App\Libraries\Memory\MemoryManager;

/**
 * Interactive chat REPL - the primary user interface for PHPClaw.
 * Supports slash commands, session management, and multi-model routing.
 */
class ChatCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:chat';
    protected $description = 'Start interactive chat session';
    protected $usage = 'agent:chat [session_name]';

    private FileStorage $storage;
    private ConfigLoader $config;
    private SessionManager $sessions;
    private ProviderManager $providers;
    private ToolRegistry $tools;
    private ModelRouter $router;
    private MemoryManager $memory;
    private string $currentRole = 'reasoning';
    private string $currentModule = 'reasoning';
    private bool $debugMode = false;

    public function run(array $params)
    {
        $this->storage = new FileStorage();
        $this->config = new ConfigLoader($this->storage);
        $this->sessions = new SessionManager($this->storage);
        $this->providers = new ProviderManager($this->config);
        $this->tools = new ToolRegistry($this->config);
        $this->router = new ModelRouter($this->config);
        $this->memory = new MemoryManager($this->storage);

        // Load providers and tools
        $this->providers->loadAll();
        $this->tools->loadAll();

        // Register providers with router
        foreach ($this->providers->all() as $name => $provider) {
            $this->router->registerProvider($name, $provider);
        }

        // Create or resume session
        $sessionName = $params[0] ?? null;
        if ($sessionName) {
            $sessions = $this->sessions->list();
            $found = null;
            foreach ($sessions as $s) {
                if ($s['name'] === $sessionName || $s['id'] === $sessionName) {
                    $found = $s['id'];
                    break;
                }
            }
            if ($found) {
                $session = $this->sessions->resume($found);
                CLI::write("Resumed session: {$session['name']}", 'green');
            } else {
                $session = $this->sessions->create($sessionName);
                CLI::write("Created session: {$session['name']}", 'green');
            }
        } else {
            $session = $this->sessions->create();
            CLI::write("New session: {$session['name']}", 'green');
        }

        $sessionId = $session['id'];
        $this->currentRole = $this->config->get('app', 'default_role', 'reasoning');
        $this->currentModule = $this->config->get('app', 'default_module', 'reasoning');

        $this->printBanner();
        $conversationHistory = [];

        // REPL loop
        while (true) {
            $route = $this->router->resolveRole($this->currentRole);
            $prompt = CLI::prompt("[{$this->currentModule}:{$route['provider']}]");

            if ($prompt === false || $prompt === null) {
                break;
            }

            $input = trim($prompt);
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

            // Send to model
            CLI::write('Thinking...', 'yellow');
            $response = $this->router->chat($this->currentRole, $conversationHistory);

            if ($response['success'] ?? false) {
                $content = $response['content'] ?? '';
                CLI::newLine();
                CLI::write($content, 'white');
                CLI::newLine();

                $conversationHistory[] = ['role' => 'assistant', 'content' => $content];

                // Log assistant message
                $this->sessions->appendTranscript($sessionId, [
                    'event_type' => 'assistant_message',
                    'role' => 'assistant',
                    'content' => $content,
                    'provider' => $response['provider'] ?? $route['provider'],
                    'model' => $response['model'] ?? $route['model'],
                    'module' => $this->currentModule,
                ]);

                if ($this->debugMode) {
                    CLI::write('[Debug] Provider: ' . ($response['provider'] ?? 'unknown') . ' Model: ' . ($response['model'] ?? 'unknown'), 'dark_gray');
                }
            } else {
                CLI::error('Error: ' . ($response['error'] ?? 'Unknown error'));
                $this->sessions->appendTranscript($sessionId, [
                    'event_type' => 'system_notice',
                    'role' => 'system',
                    'content' => 'Error: ' . ($response['error'] ?? 'Unknown error'),
                ]);
            }
        }

        CLI::write('Session saved. Goodbye!', 'green');
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
            case '/provider':
                $this->showProviders();
                break;
            case '/model':
                CLI::write("Current: " . json_encode($this->router->resolveRole($this->currentRole)), 'cyan');
                break;
            case '/role':
                if ($arg) {
                    $this->currentRole = $arg;
                    CLI::write("Role set to: {$arg}", 'green');
                } else {
                    CLI::write("Current role: {$this->currentRole}", 'cyan');
                }
                break;
            case '/module':
                if ($arg) {
                    $this->currentModule = $arg;
                    CLI::write("Module set to: {$arg}", 'green');
                } else {
                    CLI::write("Current module: {$this->currentModule}", 'cyan');
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
                CLI::write('Debug mode: ' . ($this->debugMode ? 'ON' : 'OFF'), 'yellow');
                break;
            case '/save':
                CLI::write("Session auto-saved: {$sessionId}", 'green');
                break;
            default:
                CLI::write("Unknown command: {$cmd}. Type /help for available commands.", 'red');
        }
        return null;
    }

    private function printBanner(): void
    {
        CLI::newLine();
        CLI::write('================================', 'green');
        CLI::write('  PHPClaw Agent Shell v0.1.0', 'green');
        CLI::write('================================', 'green');
        CLI::write('Type /help for commands, /exit to quit.', 'light_gray');
        CLI::newLine();
    }

    private function printHelp(): void
    {
        CLI::write('Slash Commands:', 'yellow');
        CLI::write('  /help       Show this help');
        CLI::write('  /provider   Show active providers');
        CLI::write('  /model      Show current model routing');
        CLI::write('  /role [r]   Show or set current role');
        CLI::write('  /module [m] Show or set current module');
        CLI::write('  /tools      List available tools');
        CLI::write('  /tasks      Show active tasks');
        CLI::write('  /memory     Show memory stats');
        CLI::write('  /status     Show system status');
        CLI::write('  /debug      Toggle debug mode');
        CLI::write('  /save       Confirm session save');
        CLI::write('  /exit       Exit chat');
    }

    private function showProviders(): void
    {
        foreach ($this->providers->listEnabled() as $p) {
            CLI::write("  {$p['name']}: {$p['description']}", 'cyan');
        }
    }

    private function showTools(): void
    {
        foreach ($this->tools->listAll() as $t) {
            $status = $t['enabled'] ? 'ON' : 'OFF';
            CLI::write("  [{$status}] {$t['name']}: {$t['description']}");
        }
    }

    private function showTasks(): void
    {
        $tasks = (new \App\Libraries\Tasks\TaskManager($this->storage))->list();
        if (empty($tasks)) {
            CLI::write('  No tasks.', 'light_gray');
            return;
        }
        foreach ($tasks as $t) {
            CLI::write("  [{$t['status']}] {$t['id']}: {$t['title']}");
        }
    }

    private function showMemory(): void
    {
        $stats = $this->memory->getStats();
        CLI::write("  Global notes: {$stats['global_notes']}");
        CLI::write("  Summaries: {$stats['total_summaries']}");
        CLI::write("  Compactions: {$stats['compaction_count']}");
        CLI::write("  Last compaction: " . ($stats['last_compaction'] ?? 'never'));
    }

    private function showStatus(): void
    {
        $state = $this->storage->readJson('state/service.json') ?? [];
        CLI::write("  Service: " . ($state['status'] ?? 'unknown'), 'cyan');
        $heartbeat = $this->storage->readJson('state/heartbeat.json') ?? [];
        CLI::write("  Last heartbeat: " . ($heartbeat['last_check'] ?? 'never'));
    }
}
