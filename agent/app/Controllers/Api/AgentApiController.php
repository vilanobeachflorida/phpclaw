<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
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
use App\Libraries\UI\NullUI;

/**
 * REST API controller for the PHPClaw agent.
 * Provides session-based chat over HTTP with Bearer token auth.
 */
class AgentApiController extends BaseController
{
    private FileStorage $storage;
    private ConfigLoader $config;
    private SessionManager $sessions;
    private ProviderManager $providers;
    private ToolRegistry $tools;
    private ModelRouter $router;
    private MemoryManager $memory;
    private PromptBuilder $promptBuilder;

    /**
     * Bootstrap all agent subsystems.
     */
    private function boot(): void
    {
        $this->storage   = new FileStorage();
        $this->config    = new ConfigLoader($this->storage);
        $this->sessions  = new SessionManager($this->storage);
        $this->providers = new ProviderManager($this->config);
        $this->tools     = new ToolRegistry($this->config);
        $this->router    = new ModelRouter($this->config);
        $this->memory    = new MemoryManager($this->storage);

        $this->providers->loadAll();
        $this->tools->loadAll();

        foreach ($this->providers->all() as $name => $provider) {
            $this->router->registerProvider($name, $provider);
        }

        $this->promptBuilder = new PromptBuilder($this->tools, $this->storage, $this->memory);
    }

    // ─── Chat ────────────────────────────────────────────────────────

    /**
     * POST /api/chat
     *
     * Send a message and get the agent's response.
     * Optionally provide a session_id to continue a conversation.
     *
     * Body (JSON):
     *   message     (string, required) — the user message
     *   session_id  (string, optional) — resume an existing session
     *   role        (string, optional) — override the default role
     *   module      (string, optional) — override the default module
     */
    public function chat()
    {
        $this->boot();

        $body = $this->request->getJSON(true) ?? [];

        $message = trim($body['message'] ?? '');
        if ($message === '') {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'message is required']);
        }

        $apiConf   = $this->config->load('api');
        $defaults  = $apiConf['defaults'] ?? [];
        $role      = $body['role']   ?? $defaults['role']   ?? 'reasoning';
        $module    = $body['module'] ?? $defaults['module'] ?? 'reasoning';
        $maxHistory = $defaults['max_history'] ?? 100;

        // Resolve or create session
        $sessionId = $body['session_id'] ?? null;
        if ($sessionId) {
            $session = $this->sessions->get($sessionId);
            if (!$session) {
                return $this->response
                    ->setStatusCode(404)
                    ->setJSON(['error' => 'Session not found', 'session_id' => $sessionId]);
            }
            $this->sessions->resume($sessionId);
        } else {
            $session   = $this->sessions->create('api-' . date('Ymd-His'));
            $sessionId = $session['id'];
        }

        // Rebuild conversation history from transcript
        $conversationHistory = $this->rebuildHistory($sessionId, $maxHistory);

        // Log user message
        $this->sessions->appendTranscript($sessionId, [
            'event_type' => 'user_message',
            'role'       => 'user',
            'content'    => $message,
            'module'     => $module,
        ]);

        $conversationHistory[] = ['role' => 'user', 'content' => $message];

        // Build system prompt
        $systemPrompt = $this->promptBuilder->buildSystemPrompt($module, [
            'session_id' => $sessionId,
        ]);

        // Execute the agent loop with NullUI (no terminal output)
        $usageTracker = new UsageTracker();
        $agent = new AgentExecutor(
            $this->router,
            $this->tools,
            false,
            $this->sessions,
            $sessionId,
            $usageTracker,
            new NullUI()
        );

        $result       = $agent->execute($role, $conversationHistory, $systemPrompt);
        $responseText = $result['text']       ?? '';
        $turnUsage    = $result['usage']      ?? null;
        $toolsUsed    = $result['tools_used'] ?? [];

        // Ingest into memory
        $this->memory->ingestTurn($sessionId, $message, $responseText, $toolsUsed);

        return $this->response->setJSON([
            'session_id' => $sessionId,
            'response'   => $responseText,
            'usage'      => $turnUsage,
            'tools_used' => array_map(fn($t) => $t['tool'] ?? $t, $toolsUsed),
        ]);
    }

    // ─── Sessions ────────────────────────────────────────────────────

    /**
     * GET /api/sessions
     *
     * List all sessions.
     */
    public function sessions()
    {
        $this->boot();

        $sessions = $this->sessions->list();

        return $this->response->setJSON([
            'sessions' => $sessions,
            'count'    => count($sessions),
        ]);
    }

    /**
     * GET /api/sessions/:id
     *
     * Get session details and transcript.
     */
    public function session(string $id)
    {
        $this->boot();

        $session = $this->sessions->get($id);
        if (!$session) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['error' => 'Session not found']);
        }

        $transcript = $this->sessions->getTranscript($id);

        // Extract just the user/assistant messages for readability
        $messages = [];
        foreach ($transcript as $event) {
            $type = $event['event_type'] ?? '';
            if (in_array($type, ['user_message', 'assistant_message'])) {
                $messages[] = [
                    'role'      => $event['role'] ?? 'unknown',
                    'content'   => $event['content'] ?? '',
                    'timestamp' => $event['timestamp'] ?? null,
                ];
            }
        }

        return $this->response->setJSON([
            'session'  => $session,
            'messages' => $messages,
        ]);
    }

    /**
     * POST /api/sessions/:id/archive
     *
     * Archive (close) a session.
     */
    public function archiveSession(string $id)
    {
        $this->boot();

        $result = $this->sessions->archive($id);
        if (!$result) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['error' => 'Session not found']);
        }

        return $this->response->setJSON(['status' => 'archived', 'session_id' => $id]);
    }

    // ─── Status ──────────────────────────────────────────────────────

    /**
     * GET /api/status
     *
     * Health check and system info.
     */
    public function status()
    {
        $this->boot();

        $providerList = [];
        foreach ($this->providers->listEnabled() as $p) {
            $providerList[] = [
                'name'        => $p['name'],
                'description' => $p['description'] ?? '',
            ];
        }

        $toolList = [];
        foreach ($this->tools->listAll() as $t) {
            if ($t['enabled'] ?? false) {
                $toolList[] = $t['name'];
            }
        }

        $appConf = $this->config->load('app');

        return $this->response->setJSON([
            'status'    => 'ok',
            'version'   => $appConf['version'] ?? '0.1.0',
            'providers' => $providerList,
            'tools'     => $toolList,
            'defaults'  => [
                'role'   => $appConf['default_role']   ?? 'reasoning',
                'module' => $appConf['default_module'] ?? 'reasoning',
            ],
        ]);
    }

    // ─── Browser Control ─────────────────────────────────────────────

    /**
     * GET /api/browser/pending
     *
     * Called by the Chrome extension to fetch the next pending command.
     * Returns 204 if no commands are queued.
     */
    public function browserPending()
    {
        $browserDir = rtrim(WRITEPATH, '/') . '/agent/browser';
        $queueDir = $browserDir . '/commands';

        if (!is_dir($browserDir)) mkdir($browserDir, 0755, true);
        if (!is_dir($queueDir)) mkdir($queueDir, 0755, true);

        // Update heartbeat
        file_put_contents($browserDir . '/heartbeat.json', json_encode(['timestamp' => time()]));

        // Clean stale results (older than 2 minutes)
        $resultDir = $browserDir . '/results';
        if (is_dir($resultDir)) {
            foreach (glob($resultDir . '/*.json') as $stale) {
                if (filemtime($stale) < time() - 120) @unlink($stale);
            }
        }

        // Quick-hold poll: check for commands, hold for up to 500ms if none found.
        // The 500ms hold keeps the extension's fetch in-flight, which prevents
        // Chrome from suspending the MV3 service worker. The extension loops
        // these fetches continuously to stay alive.
        // 500ms is short enough to not meaningfully block the single-threaded
        // PHP dev server for other requests (agent:chat runs in a separate process).
        $checks = 10; // 10 checks × 50ms = 500ms max hold
        for ($i = 0; $i < $checks; $i++) {
            $files = glob($queueDir . '/*.json');
            if (!empty($files)) {
                usort($files, fn($a, $b) => filemtime($a) - filemtime($b));

                foreach ($files as $file) {
                    if (filemtime($file) < time() - 90) {
                        @unlink($file);
                        continue;
                    }

                    $command = json_decode(file_get_contents($file), true);
                    @unlink($file);

                    if ($command) {
                        return $this->response
                            ->setHeader('Access-Control-Allow-Origin', '*')
                            ->setJSON($command);
                    }
                }
            }

            if ($i < $checks - 1) usleep(50000); // 50ms
        }

        return $this->response
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setStatusCode(204)->setBody('');
    }

    /**
     * POST /api/browser/result
     *
     * Called by the Chrome extension to submit the result of a command.
     * Body: { id: "command_id", success: bool, data: {...}, error: "..." }
     */
    public function browserResult()
    {
        $body = $this->request->getJSON(true) ?? [];
        $commandId = $body['id'] ?? '';

        if (!$commandId) {
            return $this->response
                ->setHeader('Access-Control-Allow-Origin', '*')
                ->setStatusCode(400)
                ->setJSON(['error' => 'Missing command id']);
        }

        $resultDir = rtrim(WRITEPATH, '/') . '/agent/browser/results';
        if (!is_dir($resultDir)) mkdir($resultDir, 0755, true);

        // Write result file that the BrowserControlTool is polling for
        $resultFile = $resultDir . "/{$commandId}.json";
        file_put_contents($resultFile, json_encode($body, JSON_PRETTY_PRINT));

        // Clean up the command file if still present
        $commandFile = rtrim(WRITEPATH, '/') . "/agent/browser/commands/{$commandId}.json";
        @unlink($commandFile);

        return $this->response
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setJSON(['status' => 'received']);
    }

    /**
     * GET /api/browser/status
     *
     * Check if the Chrome extension is connected (has polled recently).
     */
    public function browserStatus()
    {
        $heartbeatFile = rtrim(WRITEPATH, '/') . '/agent/browser/heartbeat.json';

        if (!file_exists($heartbeatFile)) {
            return $this->response->setJSON(['connected' => false, 'last_seen' => null]);
        }

        $data = json_decode(file_get_contents($heartbeatFile), true);
        $lastSeen = $data['timestamp'] ?? 0;
        $connected = (time() - $lastSeen) < 5; // Consider connected if polled in last 5 seconds

        return $this->response->setJSON([
            'connected' => $connected,
            'last_seen' => date('c', $lastSeen),
        ]);
    }

    // ─── Documentation ───────────────────────────────────────────────

    /**
     * GET /api/docs
     *
     * Serve the interactive API documentation page.
     * This endpoint does NOT require authentication.
     */
    public function docs()
    {
        return $this->response
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setBody(view('api/docs'));
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Rebuild conversation history from a session transcript.
     */
    private function rebuildHistory(string $sessionId, int $maxMessages = 100): array
    {
        $transcript = $this->sessions->getTranscript($sessionId);
        $history = [];

        foreach ($transcript as $event) {
            $type = $event['event_type'] ?? '';
            if ($type === 'user_message') {
                $history[] = ['role' => 'user', 'content' => $event['content'] ?? ''];
            } elseif ($type === 'assistant_message') {
                $history[] = ['role' => 'assistant', 'content' => $event['content'] ?? ''];
            }
        }

        // Keep only the last N messages to avoid blowing context
        if (count($history) > $maxMessages) {
            $history = array_slice($history, -$maxMessages);
        }

        return $history;
    }
}
