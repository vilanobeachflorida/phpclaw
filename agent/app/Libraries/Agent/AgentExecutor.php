<?php

namespace App\Libraries\Agent;

use App\Libraries\Router\ModelRouter;
use App\Libraries\Service\ToolRegistry;
use App\Libraries\Session\SessionManager;
use CodeIgniter\CLI\CLI;

/**
 * Core agent execution loop.
 * Sends messages to the LLM, parses tool calls from responses,
 * executes tools, feeds results back, and loops until the model
 * produces a final response with no tool calls.
 *
 * Instead of a hard iteration cap, uses smart loop detection:
 * - Tracks total tool calls across all iterations
 * - Detects repeated identical tool calls (stuck in a loop)
 * - Detects consecutive failures (model can't make progress)
 * - Nudges the model to wrap up after many iterations
 * - Only hard-stops at a very high safety limit (50 iterations)
 */
class AgentExecutor
{
    private ModelRouter $router;
    private ToolRegistry $tools;
    private ResponseParser $parser;
    private ?SessionManager $sessions;
    private ?string $sessionId;
    private bool $debug;

    /** Absolute safety limit - should never realistically hit this. */
    private int $hardLimit = 50;

    /** After this many iterations, nudge the model to wrap up. */
    private int $nudgeAfter = 15;

    /** Max consecutive identical tool call sets before declaring stuck. */
    private int $maxRepeats = 3;

    /** Max consecutive all-failed iterations before stopping. */
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

        while ($iteration < $this->hardLimit) {
            $iteration++;

            // Build messages with system prompt
            $messages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $conversationHistory
            );

            // After many iterations, nudge the model to finish up
            if ($iteration === $this->nudgeAfter + 1) {
                $messages[] = ['role' => 'system', 'content' =>
                    'You have been running tools for many iterations. Please finish up: summarize what you have so far and provide your final response to the user. Only make more tool calls if absolutely necessary to complete the task.'
                ];
            }

            if ($this->debug) {
                CLI::write("[Agent] Iteration {$iteration}, {$totalToolCalls} total tool calls, " . count($messages) . " messages", 'dark_gray');
            }

            $response = $this->router->chat($role, $messages);

            if (!($response['success'] ?? false)) {
                $error = $response['error'] ?? 'Unknown error';
                CLI::error("Provider error: {$error}");
                return "Error: {$error}";
            }

            $rawContent = $response['content'] ?? '';

            if ($this->debug) {
                CLI::write("[Agent] Raw response length: " . strlen($rawContent), 'dark_gray');
            }

            // Parse the response
            $parsed = $this->parser->parse($rawContent);

            // Show any display text before tool calls
            if ($parsed['display']) {
                $allDisplayText[] = $parsed['display'];
                // Print intermediate text so user sees progress
                if ($parsed['has_tool_calls']) {
                    CLI::write($parsed['display'], 'white');
                }
            }

            // If no tool calls, we're done
            if (!$parsed['has_tool_calls']) {
                $finalText = implode("\n\n", $allDisplayText);
                $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];
                $this->logTranscript('assistant_message', 'assistant', $finalText, $response);

                if ($this->debug) {
                    CLI::write("[Agent] Complete after {$iteration} iterations, {$totalToolCalls} tool calls", 'dark_gray');
                }
                return $finalText;
            }

            // --- Loop detection ---

            // Build a signature of this iteration's tool calls
            $callSig = $this->buildCallSignature($parsed['tool_calls']);

            // Check for repeated identical calls
            if (!empty($previousCallSignatures) && end($previousCallSignatures) === $callSig) {
                $repeatCount++;
                if ($repeatCount >= $this->maxRepeats) {
                    CLI::write("  [Agent] Detected repeated tool calls - breaking loop", 'yellow');
                    $allDisplayText[] = "(Stopped: agent was repeating the same tool calls)";
                    break;
                }
            } else {
                $repeatCount = 0;
            }
            $previousCallSignatures[] = $callSig;

            // Execute tool calls
            $toolResults = $this->executeToolCalls($parsed['tool_calls'], $response);
            $totalToolCalls += count($toolResults);

            // Check for all-failed iterations
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
                    CLI::write("  [Agent] Too many consecutive tool failures - stopping", 'yellow');
                    $allDisplayText[] = "(Stopped: tools failing repeatedly)";
                    break;
                }
            } else {
                $consecutiveFailures = 0;
            }

            // Add assistant message + tool results to conversation history
            $conversationHistory[] = ['role' => 'assistant', 'content' => $rawContent];

            $resultMessage = $this->formatToolResults($toolResults, $iteration, $totalToolCalls);
            $conversationHistory[] = ['role' => 'user', 'content' => $resultMessage];
        }

        // Reached a break or hard limit
        $finalText = implode("\n\n", $allDisplayText);
        if (empty($finalText)) {
            $finalText = "(Agent could not complete the task)";
        }
        $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];

        if ($this->debug) {
            CLI::write("[Agent] Stopped after {$iteration} iterations, {$totalToolCalls} tool calls", 'dark_gray');
        }

        return $finalText;
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

            CLI::write("  > {$toolName}", 'cyan');

            if ($this->debug) {
                CLI::write("    Args: " . json_encode($toolArgs, JSON_UNESCAPED_SLASHES), 'dark_gray');
            }

            $result = $this->tools->execute($toolName, $toolArgs);

            $success = $result['success'] ?? false;
            $statusColor = $success ? 'green' : 'red';
            $statusText = $success ? 'ok' : 'FAILED';
            CLI::write("    [{$statusText}]", $statusColor);

            if (!$success) {
                CLI::write("    " . ($result['error'] ?? 'unknown error'), 'red');
            }

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
    private function formatToolResults(array $results, int $iteration, int $totalCalls): string
    {
        $parts = ["[Tool Results - iteration {$iteration}, {$totalCalls} total calls]"];

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

        $parts[] = "\nContinue working on the user's request. Call more tools if needed, or provide your final response.";

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

    /**
     * Log a transcript event if session is active.
     */
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

    /**
     * Log a tool execution event.
     */
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
