<?php

namespace App\Libraries\Tools;

use App\Libraries\Storage\FileStorage;

/**
 * Browser Control Tool - sends commands to the PHPClaw Chrome extension.
 *
 * Uses a file-based command queue:
 *   1. Tool writes a command file to writable/agent/browser/commands/{id}.json
 *   2. Chrome extension polls GET /api/browser/pending, picks up the command
 *   3. Extension executes in the real browser, POSTs result to /api/browser/result
 *   4. Tool polls for the result file at writable/agent/browser/results/{id}.json
 */
class BrowserControlTool extends BaseTool
{
    protected string $name = 'browser_control';
    protected string $description = 'Control the user\'s REAL Chrome browser via the PHPClaw extension. Opens visible tabs, clicks buttons, types text, fills forms, takes screenshots — the user sees everything happen live. ALWAYS use this instead of browser_fetch/http_get when the user asks to open, browse, navigate, or interact with websites.';

    private string $queueDir;
    private string $resultDir;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $storagePath = rtrim(WRITEPATH, '/') . '/agent/browser';
        $this->queueDir  = $storagePath . '/commands';
        $this->resultDir = $storagePath . '/results';

        if (!is_dir($this->queueDir))  mkdir($this->queueDir, 0755, true);
        if (!is_dir($this->resultDir)) mkdir($this->resultDir, 0755, true);
    }

    public function getInputSchema(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Action to perform: navigate, snapshot, click, type, read_text, read_html, screenshot, get_links, get_forms, fill_form, submit_form, execute_js, get_tabs, new_tab, close_tab, switch_tab, scroll, wait_for, get_cookies, select, hover, go_back, go_forward, reload, release, smart_login, get_url. WORKFLOW: use navigate/new_tab first (returns a numbered list of all interactive elements). Then use element numbers with click/type (e.g. ref: 3 to click element [3]). Use snapshot to re-scan the page after changes.',
            ],
            'ref' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Element reference for click/type/select/hover. Can be: a NUMBER from the page snapshot (e.g. "3" for element [3]), a CSS selector (e.g. "#login"), or a TEXT DESCRIPTION (e.g. "Login button", "username field"). Numbers from snapshot are most reliable.',
            ],
            'url' => [
                'type' => 'string',
                'required' => false,
                'description' => 'URL for navigate/new_tab actions',
            ],
            'selector' => [
                'type' => 'string',
                'required' => false,
                'description' => 'CSS selector (legacy — prefer ref instead)',
            ],
            'text' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Text to type (for type action)',
            ],
            'clear' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Clear field before typing (for type action)',
            ],
            'code' => [
                'type' => 'string',
                'required' => false,
                'description' => 'JavaScript code to execute (for execute_js action)',
            ],
            'fields' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Map of CSS selector => value pairs (for fill_form action)',
            ],
            'tab_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Tab ID (for switch_tab/close_tab actions)',
            ],
            'direction' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Scroll direction: up, down, top, bottom (for scroll action)',
            ],
            'amount' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Scroll amount in pixels (for scroll action, default 500)',
            ],
            'value' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Value to set (for select action)',
            ],
            'outer' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Return outerHTML instead of innerHTML (for read_html action)',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Max items to return (for get_links, default 100)',
            ],
            'timeout' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Timeout in seconds (for wait_for/navigate, default 10)',
            ],
            'username' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Username for smart_login action',
            ],
            'password' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Password for smart_login action',
            ],
            'submit' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Auto-submit the form after filling (for smart_login, default true)',
            ],
            'field' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Field description for type action (e.g. "username", "search box"). Alternative to ref/selector.',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        $action = $args['action'];
        $validActions = [
            'navigate', 'get_url', 'snapshot', 'click', 'type', 'read_text', 'read_html',
            'screenshot', 'get_links', 'get_forms', 'fill_form', 'submit_form',
            'execute_js', 'get_tabs', 'new_tab', 'close_tab', 'switch_tab',
            'scroll', 'wait_for', 'get_cookies', 'select', 'hover',
            'go_back', 'go_forward', 'reload', 'release', 'smart_login',
        ];

        if (!in_array($action, $validActions)) {
            return $this->error("Invalid action: {$action}. Valid: " . implode(', ', $validActions));
        }

        // Build the command
        $commandId = bin2hex(random_bytes(8));
        $command = [
            'id'        => $commandId,
            'action'    => $action,
            'args'      => $this->buildActionArgs($action, $args),
            'timestamp' => date('c'),
        ];

        // Write command to queue
        $commandFile = $this->queueDir . "/{$commandId}.json";
        file_put_contents($commandFile, json_encode($command, JSON_PRETTY_PRINT));

        // Wait for result — browser commands go through the extension
        // which adds polling + execution + network overhead, so be generous
        $timeout = max(($args['timeout'] ?? 60), 60);
        // Page-load actions need even more time
        if (in_array($action, ['wait_for', 'navigate', 'submit_form', 'new_tab'])) {
            $timeout = max($timeout, 90);
        }

        $result = $this->waitForResult($commandId, $timeout);

        // Clean up command file if still present (normally removed by /api/browser/pending)
        @unlink($commandFile);

        if ($result === null) {
            return $this->error("Timeout waiting for browser extension response. Is the PHPClaw Chrome extension connected?");
        }

        // Clean up result file
        @unlink($this->resultDir . "/{$commandId}.json");

        if (!($result['success'] ?? false)) {
            return $this->error($result['error'] ?? 'Unknown browser error', 0, $result['data'] ?? null);
        }

        return $this->success($result['data'] ?? [], "browser_control:{$action}");
    }

    /**
     * Extract relevant args for the specific action.
     */
    private function buildActionArgs(string $action, array $args): array
    {
        $actionArgs = [];

        // Map args based on action
        $argMap = [
            'navigate'    => ['url', 'timeout'],
            'get_url'     => [],
            'snapshot'    => [],
            'click'       => ['ref', 'selector'],
            'type'        => ['ref', 'selector', 'field', 'text', 'clear'],
            'read_text'   => ['selector'],
            'read_html'   => ['selector', 'outer'],
            'screenshot'  => [],
            'get_links'   => ['selector', 'limit'],
            'get_forms'   => ['selector'],
            'fill_form'   => ['fields'],
            'submit_form' => ['selector'],
            'execute_js'  => ['code'],
            'get_tabs'    => [],
            'new_tab'     => ['url', 'timeout'],
            'close_tab'   => ['tab_id'],
            'switch_tab'  => ['tab_id'],
            'scroll'      => ['direction', 'amount', 'selector'],
            'wait_for'    => ['selector', 'timeout'],
            'get_cookies' => ['url'],
            'select'      => ['ref', 'selector', 'value'],
            'hover'       => ['ref', 'selector'],
            'go_back'     => [],
            'go_forward'  => [],
            'reload'      => [],
            'release'     => [],
            'smart_login' => ['username', 'password', 'submit'],
        ];

        $allowed = $argMap[$action] ?? [];
        foreach ($allowed as $key) {
            if (isset($args[$key])) {
                $actionArgs[$key] = $args[$key];
            }
        }

        return $actionArgs;
    }

    /**
     * Poll for result file until timeout.
     */
    private function waitForResult(string $commandId, int $timeout): ?array
    {
        $resultFile = $this->resultDir . "/{$commandId}.json";
        $deadline = time() + $timeout;
        $interval = 100000; // 100ms in microseconds

        while (time() < $deadline) {
            if (file_exists($resultFile)) {
                $data = file_get_contents($resultFile);
                $result = json_decode($data, true);
                if ($result !== null) {
                    return $result;
                }
            }
            usleep($interval);
        }

        return null;
    }

    /**
     * Get the command queue directory (used by API controller).
     */
    public function getQueueDir(): string
    {
        return $this->queueDir;
    }

    /**
     * Get the result directory (used by API controller).
     */
    public function getResultDir(): string
    {
        return $this->resultDir;
    }
}
