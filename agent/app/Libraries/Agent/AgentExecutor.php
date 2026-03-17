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
 * This is what makes PHPClaw an agent rather than a dumb chat passthrough.
 */
class AgentExecutor
{
    private ModelRouter $router;
    private ToolRegistry $tools;
    private ResponseParser $parser;
    private ?SessionManager $sessions;
    private ?string $sessionId;
    private bool $debug;

    /** Maximum tool call iterations per user message to prevent infinite loops. */
    private int $maxIterations = 10;

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
        $allDisplayText = [];

        while ($iteration < $this->maxIterations) {
            $iteration++;

            // Build messages with system prompt
            $messages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $conversationHistory
            );

            // Call LLM
            if ($this->debug) {
                CLI::write("[Agent] Iteration {$iteration}, sending " . count($messages) . " messages", 'dark_gray');
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
            }

            // If no tool calls, we're done
            if (!$parsed['has_tool_calls']) {
                // Add assistant message to history
                $finalText = implode("\n\n", $allDisplayText);
                $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];

                $this->logTranscript('assistant_message', 'assistant', $finalText, $response);
                return $finalText;
            }

            // Execute tool calls
            $toolResults = $this->executeToolCalls($parsed['tool_calls'], $response);

            // Add assistant message + tool results to conversation history
            $conversationHistory[] = ['role' => 'assistant', 'content' => $rawContent];

            // Format tool results as a message back to the model
            $resultMessage = $this->formatToolResults($toolResults);
            $conversationHistory[] = ['role' => 'user', 'content' => $resultMessage];

            if ($this->debug) {
                CLI::write("[Agent] Tool results fed back, continuing loop", 'dark_gray');
            }
        }

        // Max iterations reached
        $finalText = implode("\n\n", $allDisplayText);
        if (empty($finalText)) {
            $finalText = "(Agent reached maximum tool iterations)";
        }
        $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];
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

            CLI::write("  > Running tool: {$toolName}", 'cyan');

            if ($this->debug) {
                CLI::write("    Args: " . json_encode($toolArgs, JSON_UNESCAPED_SLASHES), 'dark_gray');
            }

            $result = $this->tools->execute($toolName, $toolArgs);

            $success = $result['success'] ?? false;
            $statusColor = $success ? 'green' : 'red';
            $statusText = $success ? 'OK' : 'FAILED';
            CLI::write("    [{$statusText}]", $statusColor);

            if ($this->debug && !$success) {
                CLI::write("    Error: " . ($result['error'] ?? 'unknown'), 'red');
            }

            // Log tool event
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
                // Truncate very large outputs
                $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                if (strlen($json) > 4000) {
                    $json = substr($json, 0, 4000) . "\n... (truncated)";
                }
                $parts[] = "<tool_result name=\"{$toolName}\" status=\"success\">\n{$json}\n</tool_result>";
            } else {
                $error = $result['error'] ?? 'Unknown error';
                $parts[] = "<tool_result name=\"{$toolName}\" status=\"error\">\n{$error}\n</tool_result>";
            }
        }

        $parts[] = "\nUse these results to respond to the user. If more tools are needed, call them. Otherwise, provide your final response.";

        return implode("\n\n", $parts);
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

        // Also log to transcript
        $status = ($result['success'] ?? false) ? 'success' : 'failed';
        $this->sessions->appendTranscript($this->sessionId, [
            'event_type' => 'tool_call',
            'role' => 'system',
            'content' => "Tool: {$tool} [{$status}]",
            'metadata' => ['tool' => $tool, 'args' => $args, 'success' => $result['success'] ?? false],
        ]);
    }
}
