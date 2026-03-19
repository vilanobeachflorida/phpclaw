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
    private ?UsageTracker $usage;
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
        ?string $sessionId = null,
        ?UsageTracker $usage = null,
        ?TerminalUI $ui = null
    ) {
        $this->router = $router;
        $this->tools = $tools;
        $this->parser = new ResponseParser();
        $this->ui = $ui ?? new TerminalUI();
        $this->debug = $debug;
        $this->sessions = $sessions;
        $this->sessionId = $sessionId;
        $this->usage = $usage;
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

    public function setUsageTracker(?UsageTracker $usage): void
    {
        $this->usage = $usage;
    }

    /**
     * Execute an agent turn: send to LLM, run tools, loop until done.
     *
     * Returns an array with:
     *   'text'  => final display text
     *   'usage' => turn usage metrics (or null if no tracker)
     */
    public function execute(string $role, array &$conversationHistory, string $systemPrompt): array
    {
        $this->usage?->startTurn();

        $iteration = 0;
        $totalToolCalls = 0;
        $allToolsUsed = [];
        $allDisplayText = [];
        $previousCallSignatures = [];
        $consecutiveFailures = 0;
        $repeatCount = 0;

        while (true) {
            $iteration++;

            // Show thinking indicator while waiting for LLM
            if ($iteration > 1) {
                // Clear previous thinking line and show fresh one
                $this->ui->thinkingDone();
            }
            $this->ui->thinking($iteration === 1 ? 'Thinking' : 'Working');

            // Build messages with system prompt
            $messages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $conversationHistory
            );

            if ($this->debug) {
                $this->ui->thinkingDone();
                $this->ui->dim("[Agent] Iteration {$iteration}, {$totalToolCalls} tool calls, " . count($messages) . " messages");
            }

            $response = $this->router->chat($role, $messages);

            // Clear thinking indicator
            $this->ui->thinkingDone();

            // Track usage from this LLM call
            $this->usage?->recordLLMCall($response);

            if (!($response['success'] ?? false)) {
                $error = $response['error'] ?? 'Unknown error';
                $this->ui->error("Provider error: {$error}");
                return $this->buildResult("Error: {$error}", $allToolsUsed);
            }

            $rawContent = $response['content'] ?? '';

            if ($this->debug) {
                $usage = $response['usage'] ?? $response['metadata']['usage'] ?? null;
                $tokenInfo = '';
                if ($usage) {
                    $in = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? '?';
                    $out = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? '?';
                    $tokenInfo = " | Tokens: {$in} in, {$out} out";
                }
                $this->ui->dim("[Agent] Response: " . strlen($rawContent) . " chars{$tokenInfo}");
            }

            // Parse the response
            $parsed = $this->parser->parse($rawContent);

            // Only show display text if it's a final response (no tool calls)
            // When there ARE tool calls, the display text is usually internal
            // reasoning that shouldn't be shown — the tool results matter.
            if ($parsed['display'] && !$parsed['has_tool_calls']) {
                $allDisplayText[] = $parsed['display'];
            }

            // If no tool calls, the model is done
            if (!$parsed['has_tool_calls']) {
                $finalText = implode("\n\n", $allDisplayText);
                $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];
                $this->logTranscript('assistant_message', 'assistant', $finalText, $response);

                if ($this->debug) {
                    $this->ui->dim("[Agent] Complete after {$iteration} iterations, {$totalToolCalls} tool calls");
                }
                return $this->buildResult($finalText, $allToolsUsed);
            }

            // --- Stuck detection ---

            $callSig = $this->buildCallSignature($parsed['tool_calls']);
            if (!empty($previousCallSignatures) && end($previousCallSignatures) === $callSig) {
                $repeatCount++;
                if ($repeatCount >= $this->maxRepeats) {
                    $stuckResult = $this->handleStuck('repeating the same tool calls', $allDisplayText, $conversationHistory);
                    if ($stuckResult !== null) {
                        return $this->buildResult($stuckResult, $allToolsUsed);
                    }
                    $repeatCount = 0;
                    $previousCallSignatures = [];
                    continue;
                }
            } else {
                $repeatCount = 0;
            }
            $previousCallSignatures[] = $callSig;

            // Execute tool calls (inject session_id for memory tools)
            $toolResults = $this->executeToolCalls($parsed['tool_calls'], $response);
            $totalToolCalls += count($toolResults);
            $this->usage?->recordToolCalls(count($toolResults));
            foreach ($toolResults as $r) {
                $allToolsUsed[] = ['tool' => $r['tool']];
            }

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
                        return $this->buildResult($stuckResult, $allToolsUsed);
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
     * Build the return value, ending the current turn in the usage tracker.
     */
    private function buildResult(string $text, array $toolsUsed = []): array
    {
        $turnUsage = $this->usage?->endTurn();
        return [
            'text'       => $text,
            'usage'      => $turnUsage,
            'tools_used' => $toolsUsed,
        ];
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

            // Inject session context for memory tools
            if ($this->sessionId && in_array($toolName, ['memory_write', 'memory_read'])) {
                $toolArgs['_session_id'] = $this->sessionId;
            }

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
            'usage' => $response['usage'] ?? $response['metadata']['usage'] ?? null,
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
