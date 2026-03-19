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
