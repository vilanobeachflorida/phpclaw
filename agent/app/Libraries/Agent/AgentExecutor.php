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
 *
 * For smaller models that tend to narrate instead of act, the executor
 * includes a continuation system that nudges the model back to making
 * tool calls when it appears to have stopped mid-task.
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

    /** Max times to nudge the model before accepting the response as final. */
    private int $maxNudges = 3;

    /** Max total iterations to prevent runaway loops. */
    private int $maxIterations = 50;

    /** Track the original user request for context. */
    private string $originalRequest = '';

    /** Track completed actions for progress awareness. */
    private array $completedActions = [];

    /** Module tool whitelist — null means allow all, array restricts. */
    private ?array $allowedTools = null;

    /** Max conversation history messages before trimming old tool results. */
    private int $maxHistoryMessages = 40;

    /** Start time for the current turn (for live elapsed display). */
    private float $turnStartTime = 0;

    /** Running token counts for live display. */
    private int $liveInputTokens = 0;
    private int $liveOutputTokens = 0;
    private int $liveToolCalls = 0;

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
     * @param string     $role                LLM role for model routing
     * @param array      &$conversationHistory  Conversation messages (modified in place)
     * @param string     $systemPrompt         System prompt text
     * @param array|null $allowedTools         Tool whitelist (null = all, array = restricted)
     *
     * Returns an array with:
     *   'text'  => final display text
     *   'usage' => turn usage metrics (or null if no tracker)
     */
    public function execute(string $role, array &$conversationHistory, string $systemPrompt, ?array $allowedTools = null): array
    {
        $this->usage?->startTurn();
        $this->allowedTools = $allowedTools;

        // Capture original request for continuation context
        $this->originalRequest = $this->extractOriginalRequest($conversationHistory);
        $this->completedActions = [];

        // Initialize live status counters
        $this->turnStartTime = microtime(true);
        $this->liveInputTokens = 0;
        $this->liveOutputTokens = 0;
        $this->liveToolCalls = 0;

        $iteration = 0;
        $totalToolCalls = 0;
        $allToolsUsed = [];
        $allDisplayText = [];
        $previousCallSignatures = [];
        $consecutiveFailures = 0;
        $repeatCount = 0;
        $nudgeCount = 0;
        $reviewDone = false;

        while (true) {
            $iteration++;

            // Safety valve: prevent truly runaway loops
            if ($iteration > $this->maxIterations) {
                $this->ui->thinkingDone();
                $this->ui->warn("Reached maximum iterations ({$this->maxIterations}). Stopping.");
                $finalText = implode("\n\n", $allDisplayText);
                if (empty($finalText)) $finalText = "(Reached iteration limit)";
                $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];
                return $this->buildResult($finalText, $allToolsUsed);
            }

            // Show thinking indicator with progress context
            if ($iteration > 1) {
                $this->ui->thinkingDone();
            }
            $thinkingMsg = $this->buildThinkingMessage($iteration, $totalToolCalls);
            $this->ui->thinking($thinkingMsg);

            // Build messages with system prompt
            $messages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $conversationHistory
            );

            if ($this->debug) {
                $this->ui->thinkingDone();
                $this->ui->dim("[Agent] Iteration {$iteration}, {$totalToolCalls} tool calls, " . count($messages) . " messages, nudges: {$nudgeCount}");
                $this->ui->thinking($thinkingMsg);
            }

            // Pass a progress callback that updates the thinking line with elapsed time
            $ui = $this->ui;
            $response = $this->router->chat($role, $messages, [
                'progress_callback' => function (float $elapsed, int $bytes) use ($ui, $thinkingMsg) {
                    $timeStr = $elapsed < 60
                        ? round($elapsed) . 's'
                        : floor($elapsed / 60) . 'm ' . (int)($elapsed % 60) . 's';
                    $ui->clearLine();
                    $ui->inline("  " . $ui->style('◆', 'bright_magenta') . " {$thinkingMsg}" . $ui->style("... {$timeStr}", 'gray'));
                },
            ]);

            // Clear thinking indicator
            $this->ui->thinkingDone();

            // Track usage from this LLM call
            $this->usage?->recordLLMCall($response);

            // Update live token counters
            $callUsage = $response['usage'] ?? $response['metadata']['usage'] ?? null;
            if ($callUsage) {
                $this->liveInputTokens += $callUsage['input_tokens'] ?? $callUsage['prompt_tokens'] ?? $callUsage['prompt_eval_count'] ?? 0;
                $this->liveOutputTokens += $callUsage['output_tokens'] ?? $callUsage['completion_tokens'] ?? $callUsage['eval_count'] ?? 0;
            }

            if (!($response['success'] ?? false)) {
                $this->ui->liveStatusDone();
                $error = $response['error'] ?? 'Unknown error';
                $this->ui->error("Provider error: {$error}");
                return $this->buildResult("Error: {$error}", $allToolsUsed);
            }

            $rawContent = $response['content'] ?? '';

            if ($this->debug) {
                $tokenInfo = '';
                if ($callUsage) {
                    $in = $callUsage['input_tokens'] ?? $callUsage['prompt_tokens'] ?? '?';
                    $out = $callUsage['output_tokens'] ?? $callUsage['completion_tokens'] ?? '?';
                    $tokenInfo = " | Tokens: {$in} in, {$out} out";
                }
                $this->ui->dim("[Agent] Response: " . strlen($rawContent) . " chars{$tokenInfo}");
            }

            // Parse the response
            $parsed = $this->parser->parse($rawContent);

            // Show thinking snippets when the model is working (has tool calls)
            // This gives the user visibility into the agent's thought process
            if ($parsed['has_tool_calls'] && $parsed['display']) {
                $snippet = $this->extractThinkingSnippet($parsed['display']);
                if ($snippet) {
                    $this->ui->dim("  " . $snippet);
                }
            }

            // Only accumulate display text if it's a final response (no tool calls)
            // Deduplicate: if the new text substantially overlaps with the last entry,
            // replace it instead of appending. This prevents the "Task complete!" x15 problem
            // that happens when the review/nudge cycle generates repeated summaries.
            if ($parsed['display'] && !$parsed['has_tool_calls']) {
                if (!empty($allDisplayText) && $this->isRepeatSummary($parsed['display'], end($allDisplayText))) {
                    // Replace — this is a revised version of the same completion summary
                    $allDisplayText[count($allDisplayText) - 1] = $parsed['display'];
                } else {
                    $allDisplayText[] = $parsed['display'];
                }
            }

            // If no tool calls, check if the model is really done or just narrating
            if (!$parsed['has_tool_calls']) {
                // Determine if this looks like a mid-task narration rather than a final answer
                $shouldNudge = $this->shouldNudgeContinuation(
                    $parsed['display'],
                    $rawContent,
                    $totalToolCalls,
                    $nudgeCount
                );

                if ($shouldNudge) {
                    $nudgeCount++;

                    if ($this->debug) {
                        $this->ui->dim("[Agent] Nudging model to continue (nudge #{$nudgeCount})");
                    }

                    // Add the model's response and a continuation nudge
                    $conversationHistory[] = ['role' => 'assistant', 'content' => $rawContent];
                    $conversationHistory[] = ['role' => 'user', 'content' => $this->buildContinuationNudge($nudgeCount)];
                    continue;
                }

                // Model is actually done — do a self-review to verify work
                // Trigger review when:
                //   - More than 1 tool call was made (non-trivial task)
                //   - We haven't already done a review (reviewCount tracks this)
                if ($totalToolCalls > 1 && !$reviewDone) {
                    $reviewDone = true;
                    $reviewResult = $this->selfReview($role, $conversationHistory, $systemPrompt, $allDisplayText);
                    if ($reviewResult !== null) {
                        // Review found issues — continue the loop
                        // Remove the last display text if it was a hallucinated summary
                        if (!empty($allDisplayText) && $this->claimsMoreThanDone(end($allDisplayText))) {
                            array_pop($allDisplayText);
                        }
                        continue;
                    }
                }

                $finalText = implode("\n\n", $allDisplayText);
                $conversationHistory[] = ['role' => 'assistant', 'content' => $finalText];
                $this->logTranscript('assistant_message', 'assistant', $finalText, $response);

                if ($this->debug) {
                    $this->ui->dim("[Agent] Complete after {$iteration} iterations, {$totalToolCalls} tool calls, {$nudgeCount} nudges");
                }
                return $this->buildResult($finalText, $allToolsUsed);
            }

            // Reset nudge count when model is making tool calls (it's working)
            $nudgeCount = 0;

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
            $this->liveToolCalls += count($toolResults);
            $this->usage?->recordToolCalls(count($toolResults));

            // Show live status after tool execution
            $this->showLiveStatus();
            foreach ($toolResults as $r) {
                $allToolsUsed[] = ['tool' => $r['tool']];
                if ($r['result']['success'] ?? false) {
                    $this->completedActions[] = $r['tool'] . ': ' . $this->summarizeArgs($r['args']);
                }
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

            // Feed results back to the model with enhanced continuation prompt
            $conversationHistory[] = ['role' => 'assistant', 'content' => $rawContent];
            $resultMessage = $this->formatToolResults($toolResults, $totalToolCalls);
            $conversationHistory[] = ['role' => 'user', 'content' => $resultMessage];

            // Trim conversation history if it's getting too long to prevent context explosion.
            // Keep the first user message and the most recent messages, summarize the middle.
            $this->trimHistory($conversationHistory);
        }
    }

    /**
     * Trim conversation history to prevent context explosion.
     *
     * When history exceeds maxHistoryMessages, keep the first user message
     * (original request) and the most recent messages, collapse the middle
     * into a short summary.
     */
    private function trimHistory(array &$history): void
    {
        if (count($history) <= $this->maxHistoryMessages) return;

        // Keep the first message (original user request) and last N messages
        $keepRecent = 20;
        $first = $history[0];
        $recent = array_slice($history, -$keepRecent);

        // Count what we're trimming
        $trimmed = count($history) - $keepRecent - 1;

        // Rebuild with a summary in the middle
        $history = [$first];
        $history[] = [
            'role' => 'user',
            'content' => "[System: {$trimmed} earlier messages trimmed to save context. " .
                         "Progress so far: {$this->liveToolCalls} tool calls completed. " .
                         "Continue working on the original request.]"
        ];
        foreach ($recent as $msg) {
            $history[] = $msg;
        }
    }

    /**
     * Determine if the model seems to have stopped mid-task and needs a nudge.
     *
     * Small models often produce text like "Now I'll create the files..."
     * without actually making tool calls. This detects that pattern.
     *
     * Also detects "hallucinated completion" — when the model claims it created
     * multiple files but only made a few file_write calls.
     */
    private function shouldNudgeContinuation(string $displayText, string $rawContent, int $totalToolCalls, int $nudgeCount): bool
    {
        // Don't nudge forever
        if ($nudgeCount >= $this->maxNudges) return false;

        // If no tool calls were made at all and the response mentions future actions, nudge
        // BUT: if the response is substantial (>500 chars), it's probably a real answer
        // to a question, not a mid-task narration. Don't nudge real answers.
        if ($totalToolCalls === 0 && $this->mentionsFutureWork($rawContent)) {
            if (mb_strlen(trim($displayText)) > 500) {
                return false; // Substantial response — this is a real answer, not narration
            }
            return true;
        }

        // If some tool calls were made but response looks like a mid-task summary
        if ($totalToolCalls > 0 && $this->looksLikeMidTaskPause($displayText, $rawContent)) {
            return true;
        }

        // If the response is very short and we've been working (likely a "done creating dirs" type msg)
        if ($totalToolCalls > 0 && mb_strlen(trim($displayText)) < 200 && $this->mentionsFutureWork($rawContent)) {
            return true;
        }

        // HALLUCINATED COMPLETION: model claims it created files but the tool counts don't match
        if ($totalToolCalls > 0 && $this->claimsMoreThanDone($displayText . ' ' . $rawContent)) {
            return true;
        }

        return false;
    }

    /**
     * Detect when the model's summary mentions more files/items than were actually
     * created via file_write tool calls. This catches the common small-model pattern
     * of "I created index.html, styles.css, and script.js" when only one file_write was made.
     */
    private function claimsMoreThanDone(string $text): bool
    {
        // Count file_write actions in completed actions
        $writeCount = 0;
        foreach ($this->completedActions as $action) {
            if (str_starts_with($action, 'file_write:') || str_starts_with($action, 'file_append:')) {
                $writeCount++;
            }
        }

        // Count files mentioned in the response (looks for filename patterns)
        $mentionedFiles = 0;
        // Match patterns like "index.html", "styles.css", "`script.js`", "**main.py**"
        if (preg_match_all('/[`*]*[\w\-\/]+\.(html|css|js|ts|php|py|json|xml|yml|yaml|md|txt|rb|go|rs|java|sh|tsx|jsx|vue|svelte)[`*]*/i', $text, $matches)) {
            // Deduplicate
            $uniqueFiles = array_unique($matches[0]);
            $mentionedFiles = count($uniqueFiles);
        }

        // If the model mentions significantly more files than it actually wrote, it's hallucinating
        if ($mentionedFiles > 1 && $writeCount < $mentionedFiles) {
            if ($this->debug) {
                $this->ui->dim("[Agent] Hallucination check: model mentions {$mentionedFiles} files but only wrote {$writeCount}");
            }
            return true;
        }

        return false;
    }

    /**
     * Check if the response mentions planned future actions (suggesting it's not done).
     */
    private function mentionsFutureWork(string $text): bool
    {
        $patterns = [
            '/\b(next|now)\s+(i\'?ll|i\s+will|we\s+will|let\s+me|i\s+need\s+to|we\s+need\s+to)\b/i',
            '/\b(going\s+to|need\s+to|should|will)\s+(create|write|add|build|implement|set\s+up|generate|make|configure|modify|update)\b/i',
            '/\bstep\s+\d+/i',
            '/\bfirst,?\s+(let\'?s|i\'?ll|we\'?ll)\b/i',
            '/\bhere\'?s?\s+(the|my|our)\s+(plan|approach|strategy)\b/i',
            '/\bnow\s+(?:let\'?s|i\'?ll|we\s+can)\s+(?:move|proceed|continue)\b/i',
            '/\bi\'?(?:ll|m going to)\s+(?:start|begin)\s+(?:by|with)\b/i',
            '/\bremaining\s+(?:steps|tasks|files|work)\b/i',
            '/\bthen\s+(?:i\'?ll|we\'?ll|i\s+will)\b/i',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $text)) return true;
        }

        return false;
    }

    /**
     * Check if the response looks like a mid-task pause rather than completion.
     */
    private function looksLikeMidTaskPause(string $displayText, string $rawContent): bool
    {
        $combined = $displayText . ' ' . $rawContent;

        // Mentions creating/writing things that haven't been done via tools
        $actionWords = ['create', 'write', 'add', 'generate', 'build', 'implement', 'set up'];
        $futureIndicators = ['will', 'going to', 'need to', 'should', "let's", "let me", "i'll", 'next'];

        $hasAction = false;
        $hasFuture = false;
        $lower = strtolower($combined);

        foreach ($actionWords as $w) {
            if (str_contains($lower, $w)) { $hasAction = true; break; }
        }
        foreach ($futureIndicators as $w) {
            if (str_contains($lower, $w)) { $hasFuture = true; break; }
        }

        // Mentions a list of files/items to create
        if (preg_match('/(?:files?|pages?|components?|sections?)\s*(?:to\s+)?(?:create|include|build).*?:/i', $combined)) {
            return true;
        }

        return $hasAction && $hasFuture;
    }

    /**
     * Build a nudge message to get the model back on track.
     */
    private function buildContinuationNudge(int $nudgeCount): string
    {
        $progress = '';
        if (!empty($this->completedActions)) {
            $recent = array_slice($this->completedActions, -5);
            $progress = "So far you've done: " . implode(', ', $recent) . ".\n\n";
        }

        switch ($nudgeCount) {
            case 1:
                return $progress .
                    "Don't describe what you plan to do — actually do it now. " .
                    "Use your tools to take the next action. Make a tool_call for the next step.";

            case 2:
                return $progress .
                    "You are narrating instead of acting. You MUST use tool_call to proceed. " .
                    "Pick the single most important next step and execute it with a tool_call right now. " .
                    "Do NOT write any explanation — just the tool_call.";

            default:
                return $progress .
                    "IMPORTANT: Your ONLY job right now is to make a tool_call. " .
                    "No text, no explanation. Just output a tool_call for the next action. " .
                    "Example: <tool_call>{\"name\": \"file_write\", \"args\": {\"path\": \"...\", \"content\": \"...\"}}</tool_call>";
        }
    }

    /**
     * After the model signals completion, do a verification review.
     *
     * Instead of trusting the model's claims, we:
     * 1. Check which files were actually written via tools
     * 2. Use dir_list to verify they exist on disk
     * 3. If files are missing, tell the model exactly what's missing and nudge it to create them
     *
     * Returns null if the review passed (no issues), or modifies
     * conversationHistory and returns non-null to continue the loop.
     */
    private function selfReview(string $role, array &$conversationHistory, string $systemPrompt, array &$allDisplayText): ?string
    {
        // Gather paths that were written to
        $writtenPaths = [];
        $createdDirs = [];
        foreach ($this->completedActions as $action) {
            if (str_starts_with($action, 'file_write:')) {
                $writtenPaths[] = trim(substr($action, strlen('file_write:')));
            } elseif (str_starts_with($action, 'mkdir:')) {
                $createdDirs[] = trim(substr($action, strlen('mkdir:')));
            }
        }

        // If directories were created but few files written, verify the dirs
        if (!empty($createdDirs) && count($writtenPaths) <= 1) {
            // Use dir_list tool to check what actually exists
            $verifyResults = [];
            foreach (array_slice($createdDirs, 0, 3) as $dir) {
                $result = $this->tools->execute('dir_list', ['path' => $dir]);
                if ($result['success'] ?? false) {
                    $entries = $result['data'] ?? [];
                    $fileCount = 0;
                    if (is_array($entries)) {
                        foreach ($entries as $entry) {
                            if (is_array($entry) && ($entry['type'] ?? '') === 'file') $fileCount++;
                            elseif (is_string($entry)) $fileCount++;
                        }
                    }
                    $verifyResults[$dir] = $fileCount;
                }
            }

            // Check if dirs are mostly empty (model didn't write files)
            $emptyDirs = [];
            foreach ($verifyResults as $dir => $fileCount) {
                if ($fileCount < 2) $emptyDirs[] = $dir;
            }

            if (!empty($emptyDirs)) {
                if ($this->debug) {
                    $this->ui->dim("[Agent] Review: directories exist but are mostly empty: " . implode(', ', $emptyDirs));
                }

                $reviewPrompt = "STOP. I checked your work and there's a problem.\n\n" .
                    "Original request: \"{$this->truncate($this->originalRequest, 300)}\"\n\n" .
                    "You created directories but these are empty or nearly empty:\n" .
                    implode("\n", array_map(fn($d) => "- {$d} ({$verifyResults[$d]} files)", $emptyDirs)) . "\n\n" .
                    "Files actually written: " . (empty($writtenPaths) ? "NONE" : implode(', ', $writtenPaths)) . "\n\n" .
                    "You need to create the actual content files. Start with the most important file and use file_write to create it now. " .
                    "Do NOT describe what you'll create — just make the tool_call.";

                $conversationHistory[] = ['role' => 'user', 'content' => $reviewPrompt];
                return 'continue';
            }
        }

        // Also check: did the model's last response mention files that weren't written?
        $lastDisplay = end($allDisplayText) ?: '';
        if ($this->claimsMoreThanDone($lastDisplay)) {
            $reviewPrompt = "STOP. Your summary mentions files that don't exist yet.\n\n" .
                "Files actually written with file_write: " . (empty($writtenPaths) ? "NONE" : implode(', ', $writtenPaths)) . "\n\n" .
                "Go back and create the missing files. Use file_write for each one. Start now with the next file.";

            $conversationHistory[] = ['role' => 'user', 'content' => $reviewPrompt];
            return 'continue';
        }

        // Everything looks consistent — ask model for a brief review
        $reviewPrompt = "Quick check — the original request was: \"{$this->truncate($this->originalRequest, 200)}\"\n" .
            "Files written: " . (empty($writtenPaths) ? "none" : implode(', ', $writtenPaths)) . "\n" .
            "Is anything missing? If yes, create it with tool_call. If complete, give a brief summary.";

        $conversationHistory[] = ['role' => 'user', 'content' => $reviewPrompt];

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $conversationHistory
        );

        $this->ui->thinking('Reviewing');
        $response = $this->router->chat($role, $messages);
        $this->ui->thinkingDone();

        $this->usage?->recordLLMCall($response);

        if (!($response['success'] ?? false)) return null;

        $rawContent = $response['content'] ?? '';
        $parsed = $this->parser->parse($rawContent);

        if ($parsed['has_tool_calls']) {
            // Review found issues — add response and continue loop
            $conversationHistory[] = ['role' => 'assistant', 'content' => $rawContent];

            // Execute the review's tool calls
            $toolResults = $this->executeToolCalls($parsed['tool_calls'], $response);
            $resultMessage = $this->formatToolResults($toolResults, count($this->completedActions));
            $conversationHistory[] = ['role' => 'user', 'content' => $resultMessage];

            foreach ($toolResults as $r) {
                if ($r['result']['success'] ?? false) {
                    $this->completedActions[] = '[review] ' . $r['tool'] . ': ' . $this->summarizeArgs($r['args']);
                }
            }

            return 'continue'; // Signal to continue the loop
        }

        // Review passed — add the review summary to display text
        if ($parsed['display']) {
            array_pop($conversationHistory); // remove the review prompt
            $allDisplayText[] = $parsed['display'];
        } else {
            array_pop($conversationHistory);
        }

        return null;
    }

    /**
     * Build a contextual thinking message based on what's happened so far.
     */
    private function buildThinkingMessage(int $iteration, int $totalToolCalls): string
    {
        if ($iteration === 1) {
            // Show a hint of what the agent is working on
            $request = mb_substr(trim($this->originalRequest), 0, 60);
            if (mb_strlen($this->originalRequest) > 60) $request .= '...';
            return $request ? "Reading: {$request}" : 'Thinking';
        }

        // Show what just happened for context
        if (!empty($this->completedActions)) {
            $last = end($this->completedActions);
            // Extract just the tool name and key detail
            $parts = explode(':', $last, 2);
            $lastTool = trim($parts[0]);
            $lastDetail = isset($parts[1]) ? trim($parts[1]) : '';

            // Build a short context string
            switch ($lastTool) {
                case 'file_write':
                case 'file_append':
                    $filename = $lastDetail ? basename($lastDetail) : '';
                    return $filename ? "Wrote {$filename}, continuing" : "Writing files";
                case 'mkdir':
                    return "Created directories, continuing";
                case 'shell_exec':
                    return "Ran command, continuing";
                case 'file_read':
                    $filename = $lastDetail ? basename($lastDetail) : '';
                    return $filename ? "Read {$filename}, thinking" : "Reading files";
                case 'grep_search':
                    return "Searched code, thinking";
                case 'dir_list':
                    return "Checked files, continuing";
                case 'code_patch':
                    return "Patched code, continuing";
                case 'test_runner':
                    return "Ran tests, reviewing";
                case 'lint_check':
                    return "Ran linter, reviewing";
                case 'build_runner':
                    return "Building project";
                case 'git_ops':
                    return "Checked git, continuing";
                default:
                    return "Working ({$totalToolCalls} tools used)";
            }
        }

        return "Working";
    }

    /**
     * Generate a short human-readable description of what a tool call is about to do.
     */
    private function describeToolCall(string $tool, array $args): string
    {
        switch ($tool) {
            case 'file_write':
            case 'file_append':
                $path = $args['path'] ?? '';
                return $path ? basename($path) : '';
            case 'file_read':
                $path = $args['path'] ?? '';
                return $path ? basename($path) : '';
            case 'mkdir':
                $path = $args['path'] ?? '';
                return $path ? basename($path) . '/' : '';
            case 'shell_exec':
                $cmd = $args['command'] ?? '';
                return mb_strlen($cmd) > 50 ? mb_substr($cmd, 0, 47) . '...' : $cmd;
            case 'grep_search':
                return $args['pattern'] ?? '';
            case 'dir_list':
                $path = $args['path'] ?? '.';
                return basename($path) . '/';
            case 'code_patch':
                $path = $args['path'] ?? '';
                return $path ? basename($path) : '';
            case 'git_ops':
                return $args['operation'] ?? '';
            case 'test_runner':
            case 'lint_check':
            case 'build_runner':
            case 'project_detect':
                return $args['action'] ?? '';
            case 'exec_target':
                return ($args['action'] ?? '') . ($args['target'] ? ' → ' . $args['target'] : '');
            default:
                // Use the first string arg as description
                foreach ($args as $v) {
                    if (is_string($v) && mb_strlen($v) > 2 && mb_strlen($v) < 60) {
                        return $v;
                    }
                }
                return '';
        }
    }

    /**
     * Check if a new display text is a repeated/revised version of an existing one.
     * Catches the pattern where the model says "Task complete!" multiple times
     * with slightly different wording each time.
     */
    private function isRepeatSummary(string $new, string $existing): bool
    {
        // If either is very short, don't consider it a repeat
        if (mb_strlen($new) < 50 || mb_strlen($existing) < 50) return false;

        // Check for shared key phrases that indicate completion summaries
        $completionPhrases = ['task complete', 'complete!', 'done!', 'ready to use', 'ready!', 'created successfully', 'has been created', 'is complete', 'project is complete', 'all files'];
        $newLower = strtolower($new);
        $existingLower = strtolower($existing);

        $newHasCompletion = false;
        $existingHasCompletion = false;
        foreach ($completionPhrases as $phrase) {
            if (str_contains($newLower, $phrase)) $newHasCompletion = true;
            if (str_contains($existingLower, $phrase)) $existingHasCompletion = true;
        }

        // Both are completion summaries — this is a repeat
        if ($newHasCompletion && $existingHasCompletion) return true;

        // Check text similarity — if >40% of words overlap, it's a repeat
        $newWords = array_unique(str_word_count($newLower, 1));
        $existingWords = array_unique(str_word_count($existingLower, 1));
        if (empty($newWords) || empty($existingWords)) return false;

        $overlap = count(array_intersect($newWords, $existingWords));
        $similarity = $overlap / max(count($newWords), count($existingWords));

        return $similarity > 0.4;
    }

    /**
     * Show a persistent status line with running token/time stats.
     * Printed as a dim line that stays on screen (not an in-place overwrite).
     */
    private function showLiveStatus(): void
    {
        $elapsed = microtime(true) - $this->turnStartTime;
        $parts = [];

        if ($this->liveInputTokens > 0 || $this->liveOutputTokens > 0) {
            $parts[] = $this->formatTokenCount($this->liveInputTokens) . ' in';
            $parts[] = $this->formatTokenCount($this->liveOutputTokens) . ' out';
        }

        if ($this->liveToolCalls > 0) {
            $n = $this->liveToolCalls;
            $parts[] = "{$n} tool" . ($n !== 1 ? 's' : '');
        }

        if ($elapsed >= 1.0) {
            $parts[] = $this->formatElapsed($elapsed);
        }

        if (!empty($parts)) {
            $this->ui->dim("  ─ " . implode(' · ', $parts) . " ─");
        }
    }

    private function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1_000_000) return round($tokens / 1_000_000, 1) . 'M';
        if ($tokens >= 1_000) return round($tokens / 1_000, 1) . 'k';
        return (string)$tokens;
    }

    private function formatElapsed(float $seconds): string
    {
        if ($seconds < 60) return round($seconds, 1) . 's';
        $m = (int)($seconds / 60);
        $s = (int)($seconds % 60);
        return "{$m}m {$s}s";
    }

    /**
     * Extract a short thinking snippet from the model's display text.
     *
     * When the model makes tool calls, it often includes brief reasoning text
     * like "Creating the main index.html with SEO optimization..." or
     * "I'll set up the directory structure first". We extract the first
     * meaningful line and truncate it to show as a dimmed status line,
     * similar to how Claude Code shows thought snippets.
     */
    private function extractThinkingSnippet(string $text): ?string
    {
        $text = trim($text);
        if (empty($text)) return null;

        // Split into lines and find the first substantive one
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip markdown headers
            if (str_starts_with($line, '#')) continue;

            // Skip bullet points that are just lists
            if (preg_match('/^[-*]\s+`/', $line)) continue;

            // Skip lines that are just filenames or paths
            if (preg_match('#^[/~.][\w/.-]+$#', $line)) continue;

            // Clean up common prefixes
            $line = preg_replace('/^(okay,?\s*|alright,?\s*|sure,?\s*|great,?\s*)/i', '', $line);
            $line = trim($line);

            if (empty($line)) continue;

            // Truncate to a reasonable length
            if (mb_strlen($line) > 120) {
                $line = mb_substr($line, 0, 117) . '...';
            }

            return $line;
        }

        return null;
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
     * Enforces the module tool whitelist — rejects tools not in the allowed list.
     */
    private function executeToolCalls(array $toolCalls, array $providerResponse): array
    {
        $results = [];

        foreach ($toolCalls as $call) {
            $toolName = $call['name'];
            $toolArgs = $call['args'] ?? [];

            // Enforce module tool whitelist
            if ($this->allowedTools !== null && !in_array($toolName, $this->allowedTools, true)) {
                $result = ['success' => false, 'error' => "Tool '{$toolName}' is not available in the current module"];
                $this->ui->toolCall($toolName, false, 'not available in this module');
                $results[] = ['tool' => $toolName, 'args' => $toolArgs, 'result' => $result];
                continue;
            }

            // Inject session context for memory tools
            if ($this->sessionId && in_array($toolName, ['memory_write', 'memory_read'])) {
                $toolArgs['_session_id'] = $this->sessionId;
            }

            if ($this->debug) {
                $this->ui->dim("    Args: " . json_encode($toolArgs, JSON_UNESCAPED_SLASHES));
            }

            // Show what we're about to do BEFORE execution
            $pendingDetail = $this->describeToolCall($toolName, $toolArgs);
            $this->ui->toolPending($toolName, $pendingDetail);

            $result = $this->tools->execute($toolName, $toolArgs);
            $success = $result['success'] ?? false;

            // Replace the pending line with the final result
            $this->ui->clearLine();
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
     * Includes progress awareness and strong continuation guidance.
     */
    private function formatToolResults(array $results, int $totalToolCalls): string
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

        // Build progress-aware continuation prompt
        $parts[] = "\n[Continuation]";
        $parts[] = "Tools used so far: {$totalToolCalls}. Original request: \"{$this->truncate($this->originalRequest, 200)}\"";

        if (!empty($this->completedActions)) {
            $recent = array_slice($this->completedActions, -8);
            $parts[] = "Completed: " . implode(' | ', $recent);
        }

        $parts[] = "If there is more work to do, make your next tool_call now. Do NOT narrate what you plan to do — just do it.";
        $parts[] = "If the task is fully complete, provide a brief summary of what was accomplished.";

        return implode("\n\n", $parts);
    }

    /**
     * Extract the original user request from conversation history.
     */
    private function extractOriginalRequest(array $history): string
    {
        // Find the last user message
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'user') {
                $content = $history[$i]['content'] ?? '';
                // Skip tool result messages
                if (!str_starts_with($content, '[Tool Results]')) {
                    return $content;
                }
            }
        }
        return '';
    }

    /**
     * Create a short summary of tool args for progress tracking.
     */
    private function summarizeArgs(array $args): string
    {
        // For path-like args, use basename to keep it short and meaningful
        foreach (['path', 'file'] as $key) {
            if (isset($args[$key]) && is_string($args[$key]) && $args[$key] !== '') {
                return basename($args[$key]);
            }
        }
        // For other args, use the value directly
        foreach (['command', 'action', 'url', 'goal'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $val = $args[$key];
                if (mb_strlen($val) > 60) $val = mb_substr($val, 0, 57) . '...';
                return $val;
            }
        }
        $keys = array_keys($args);
        return implode(',', array_slice($keys, 0, 3));
    }

    /**
     * Truncate a string with ellipsis.
     */
    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max - 3) . '...';
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
