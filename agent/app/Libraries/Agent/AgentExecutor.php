<?php

namespace App\Libraries\Agent;

use App\Libraries\Router\ModelRouter;
use App\Libraries\Service\ToolRegistry;
use App\Libraries\Session\SessionManager;
use App\Libraries\UI\TerminalUI;

/**
 * Core agent execution loop.
 * Sends messages to the LLM, parses tool calls from responses,
 * executes tools, feeds results back, and loops until the model
 * produces a final response with no tool calls.
 *
 * There is no iteration limit. The agent runs until:
 * - The model responds with no tool calls (task complete)
 * - The model is stuck making identical calls repeatedly
 * - The model can't make progress (consecutive failures)
 *
 * If stuck, the agent asks the user what to do rather than silently stopping.
 */
class AgentExecutor
{
    private ModelRouter $router;
    private ToolRegistry $tools;
    private ResponseParser $parser;
    private TerminalUI $ui;
    private ?SessionManager $sessions;
    private ?string $sessionId;
    private bool $debug;

    /** Identical call sets this many times in a row = stuck. */
    private int $maxRepeats = 3;

    /** All tools failing this many iterations in a row = stuck. */
    private int $maxConsecutiveFailures = 3;

    public function __construct(
        ModelRouter $router,
        ToolRegistry $tools,
        bool $debug = false,
        ?SessionManager $sessions = null,
        ?string $sessionId = null
    ) {
        $this->router = $router;
        $this->tools = $tools;
        $this->parser = new ResponseParser();
        $this->ui = new TerminalUI();
        $this->debug = $debug;
        $this->sessions = $sessions;
        $this->sessionId = $sessionId;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function setSession(?SessionManager $sessions, ?string $sessionId): void
    {
        $this->sessions = $sessions;
        $this->sessionId = $sessionId;
    }

    /**
     * Execute an agent turn: send to LLM, run tools, loop until done.
     * Returns the final display text for the user.
     */
    public function execute(string $role, array &$conversationHistory, string $systemPrompt): string
    {
        $iteration = 0;
        $totalToolCalls = 0;
        $allDisplayText = [];
        $previousCallSignatures = [];
        $consecutiveFailures = 0;
        $repeatCount = 0;

        while (true) {
            $iteration++;

            // Build messages with system prompt
            $messages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $conversationHistory
            );

            if ($this->debug) {
                $this->ui->dim("[Agent] Iteration {$iteration}, {$totalToolCalls} total tool calls, " . count($messages) . " messages");
            }

            $response = $this->router->chat($role, $messages);

            if (!($response['success'] ?? false)) {
                $error = $response['error'] ?? 'Unknown error';
                $this->ui->error("Provider error: {$error}");
                return "Error: {$error}";
            }

            $rawContent = $response['content'] ?? '';

            if ($this->debug) {
                $this->ui->dim("[Agent] Raw response length: " . strlen($rawContent));
            }

            // Parse the response
            $parsed = $this->parser->parse($rawContent);

            // Show any display text before tool calls
            if ($parsed['display']) {
                $allDisplayText[] = $parsed['display'];
                if ($parsed['has_tool_calls']) {
                    $this->ui->write("  {$parsed['display']}", 'white');
                }
            }

            // If no tool calls, the model is done
            if (!$parsed['has_tool_calls']) {
                $finalText = implode("\n\n", $allDisplayText);
                $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];
                $this->logTranscript('assistant_message', 'assistant', $finalText, $response);

                if ($this->debug) {
                    $this->ui->dim("[Agent] Complete after {$iteration} iterations, {$totalToolCalls} tool calls");
                }
                return $finalText;
            }

            // --- Stuck detection ---

            // Check for repeated identical tool calls
            $callSig = $this->buildCallSignature($parsed['tool_calls']);
            if (!empty($previousCallSignatures) && end($previousCallSignatures) === $callSig) {
                $repeatCount++;
                if ($repeatCount >= $this->maxRepeats) {
                    $stuckResult = $this->handleStuck('repeating the same tool calls', $allDisplayText, $conversationHistory);
                    if ($stuckResult !== null) {
                        return $stuckResult;
                    }
                    $repeatCount = 0;
                    $previousCallSignatures = [];
                    continue;
                }
            } else {
                $repeatCount = 0;
            }
            $previousCallSignatures[] = $callSig;

            // Execute tool calls
            $toolResults = $this->executeToolCalls($parsed['tool_calls'], $response);
            $totalToolCalls += count($toolResults);

            // Check for consecutive all-failed iterations
            $allFailed = true;
            foreach ($toolResults as $r) {
                if ($r['result']['success'] ?? false) {
                    $allFailed = false;
                    break;
                }
            }

            if ($allFailed) {
                $consecutiveFailures++;
                if ($consecutiveFailures >= $this->maxConsecutiveFailures) {
                    $stuckResult = $this->handleStuck('tools are failing repeatedly', $allDisplayText, $conversationHistory);
                    if ($stuckResult !== null) {
                        return $stuckResult;
                    }
                    $consecutiveFailures = 0;
                    continue;
                }
            } else {
                $consecutiveFailures = 0;
            }

            // Feed results back to the model
            $conversationHistory[] = ['role' => 'assistant', 'content' => $rawContent];
            $resultMessage = $this->formatToolResults($toolResults);
            $conversationHistory[] = ['role' => 'user', 'content' => $resultMessage];
        }
    }

    /**
     * When the agent appears stuck, ask the user what to do.
     * Returns null if user wants to continue, or the final text to return.
     */
    private function handleStuck(string $reason, array &$allDisplayText, array &$conversationHistory): ?string
    {
        $this->ui->newLine();
        $this->ui->warnBox("Agent appears stuck: {$reason}");

        $choice = $this->ui->menu('What would you like to do?', [
            ['label' => 'Continue',         'description' => 'Let the agent keep trying'],
            ['label' => 'New instructions',  'description' => 'Give the agent new directions'],
            ['label' => 'Stop',             'description' => 'End this turn'],
        ]);

        switch ($choice) {
            case 2: // Stop
                $finalText = implode("\n\n", $allDisplayText);
                if (empty($finalText)) {
                    $finalText = "(Stopped by user)";
                }
                $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];
                return $finalText;

            case 1: // New instructions
                $instruction = $this->ui->prompt('Enter instructions');
                if ($instruction) {
                    $conversationHistory[] = ['role' => 'user', 'content' => $instruction];
                }
                return null;

            default: // Continue
                return null;
        }
    }

    /**
     * Execute a list of tool calls and return results.
     */
    private function executeToolCalls(array $toolCalls, array $providerResponse): array
    {
        $results = [];

        foreach ($toolCalls as $call) {
            $toolName = $call['name'];
            $toolArgs = $call['args'] ?? [];

            if ($this->debug) {
                $this->ui->dim("    Args: " . json_encode($toolArgs, JSON_UNESCAPED_SLASHES));
            }

            $result = $this->tools->execute($toolName, $toolArgs);
            $success = $result['success'] ?? false;

            $detail = '';
            if (!$success) {
                $detail = $result['error'] ?? 'unknown error';
            }
            $this->ui->toolCall($toolName, $success, $detail);

            $this->logToolEvent($toolName, $toolArgs, $result, $providerResponse);

            $results[] = [
                'tool' => $toolName,
                'args' => $toolArgs,
                'result' => $result,
            ];
        }

        return $results;
    }

    /**
     * Format tool results into a message the model can understand.
     */
    private function formatToolResults(array $results): string
    {
        $parts = ["[Tool Results]"];

        foreach ($results as $r) {
            $toolName = $r['tool'];
            $result = $r['result'];
            $success = $result['success'] ?? false;

            if ($success) {
                $data = $result['data'] ?? $result;
                $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                if (strlen($json) > 8000) {
                    $json = substr($json, 0, 8000) . "\n... (truncated, " . strlen($json) . " bytes total)";
                }
                $parts[] = "<tool_result name=\"{$toolName}\" status=\"success\">\n{$json}\n</tool_result>";
            } else {
                $error = $result['error'] ?? 'Unknown error';
                $parts[] = "<tool_result name=\"{$toolName}\" status=\"error\">\n{$error}\n</tool_result>";
            }
        }

        $parts[] = "\nContinue working on the user's request. Call more tools if needed, or provide your final response when done.";

        return implode("\n\n", $parts);
    }

    /**
     * Build a fingerprint of a set of tool calls for loop detection.
     */
    private function buildCallSignature(array $toolCalls): string
    {
        $sigs = [];
        foreach ($toolCalls as $call) {
            $sigs[] = ($call['name'] ?? '') . ':' . json_encode($call['args'] ?? []);
        }
        sort($sigs);
        return md5(implode('|', $sigs));
    }

    private function logTranscript(string $type, string $role, string $content, array $response = []): void
    {
        if (!$this->sessions || !$this->sessionId) return;

        $this->sessions->appendTranscript($this->sessionId, [
            'event_type' => $type,
            'role' => $role,
            'content' => $content,
            'provider' => $response['provider'] ?? null,
            'model' => $response['model'] ?? null,
        ]);
    }

    private function logToolEvent(string $tool, array $args, array $result, array $response): void
    {
        if (!$this->sessions || !$this->sessionId) return;

        $this->sessions->appendToolEvent($this->sessionId, [
            'tool' => $tool,
            'args' => $args,
            'success' => $result['success'] ?? false,
            'error' => $result['error'] ?? null,
            'provider' => $response['provider'] ?? null,
            'model' => $response['model'] ?? null,
        ]);

        $status = ($result['success'] ?? false) ? 'success' : 'failed';
        $this->sessions->appendTranscript($this->sessionId, [
            'event_type' => 'tool_call',
            'role' => 'system',
            'content' => "Tool: {$tool} [{$status}]",
            'metadata' => ['tool' => $tool, 'args' => $args, 'success' => $result['success'] ?? false],
        ]);
    }
}
