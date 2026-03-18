<?php

namespace App\Libraries\Agent;

/**
 * Tracks token usage and estimated costs across an entire session.
 *
 * Accumulates per-turn metrics from provider responses and provides
 * session totals, per-model breakdowns, and cost estimates.
 *
 * Cost data is approximate — based on published API pricing.
 * Local providers (Ollama, LM Studio, Claude Code CLI) show $0.00.
 */
class UsageTracker
{
    /** Per-model pricing: [input_cost_per_1M, output_cost_per_1M] */
    private const MODEL_PRICING = [
        // Claude models (Anthropic)
        'claude-opus-4'           => [15.00, 75.00],
        'claude-sonnet-4'         => [3.00,  15.00],
        'claude-haiku-4'          => [0.80,  4.00],
        'claude-3-5-sonnet'       => [3.00,  15.00],
        'claude-3-5-haiku'        => [0.80,  4.00],
        'claude-3-opus'           => [15.00, 75.00],
        'claude-3-sonnet'         => [3.00,  15.00],
        'claude-3-haiku'          => [0.25,  1.25],
        // OpenAI models
        'gpt-4o'                  => [2.50,  10.00],
        'gpt-4o-mini'             => [0.15,  0.60],
        'gpt-4-turbo'             => [10.00, 30.00],
        'gpt-4'                   => [30.00, 60.00],
        'gpt-3.5-turbo'           => [0.50,  1.50],
        'o1'                      => [15.00, 60.00],
        'o1-mini'                 => [3.00,  12.00],
        'o3'                      => [10.00, 40.00],
        'o3-mini'                 => [1.10,  4.40],
        'o4-mini'                 => [1.10,  4.40],
    ];

    /** Free/local provider types that have no cost. */
    private const FREE_PROVIDERS = ['ollama', 'lmstudio', 'claude_code', 'openllm'];

    private int $totalInputTokens = 0;
    private int $totalOutputTokens = 0;
    private float $totalCost = 0.0;
    private int $totalRequests = 0;
    private int $totalToolCalls = 0;
    private float $sessionStartTime;

    /** Per-model breakdown: model => [input, output, cost, requests] */
    private array $perModel = [];

    /** Per-turn records for detailed history. */
    private array $turns = [];

    /** Current turn accumulator. */
    private int $currentTurnInput = 0;
    private int $currentTurnOutput = 0;
    private float $currentTurnCost = 0.0;
    private int $currentTurnRequests = 0;
    private int $currentTurnToolCalls = 0;
    private float $currentTurnStartTime = 0;

    public function __construct()
    {
        $this->sessionStartTime = microtime(true);
    }

    /**
     * Start tracking a new turn (user message -> agent response cycle).
     */
    public function startTurn(): void
    {
        $this->currentTurnInput = 0;
        $this->currentTurnOutput = 0;
        $this->currentTurnCost = 0.0;
        $this->currentTurnRequests = 0;
        $this->currentTurnToolCalls = 0;
        $this->currentTurnStartTime = microtime(true);
    }

    /**
     * Record usage from a single LLM call (one iteration of the agent loop).
     */
    public function recordLLMCall(array $response): void
    {
        $provider = $response['provider'] ?? 'unknown';
        $model = $response['model'] ?? 'unknown';
        $usage = $response['usage'] ?? $response['metadata']['usage'] ?? null;

        $inputTokens = 0;
        $outputTokens = 0;

        if ($usage) {
            // Normalize different provider formats
            $inputTokens = $usage['input_tokens']
                ?? $usage['prompt_tokens']
                ?? $usage['prompt_eval_count']
                ?? 0;
            $outputTokens = $usage['output_tokens']
                ?? $usage['completion_tokens']
                ?? $usage['eval_count']
                ?? 0;
        }

        // Calculate cost
        $cost = $this->estimateCost($provider, $model, $inputTokens, $outputTokens);

        // Accumulate turn totals
        $this->currentTurnInput += $inputTokens;
        $this->currentTurnOutput += $outputTokens;
        $this->currentTurnCost += $cost;
        $this->currentTurnRequests++;

        // Accumulate session totals
        $this->totalInputTokens += $inputTokens;
        $this->totalOutputTokens += $outputTokens;
        $this->totalCost += $cost;
        $this->totalRequests++;

        // Per-model breakdown
        if (!isset($this->perModel[$model])) {
            $this->perModel[$model] = ['input' => 0, 'output' => 0, 'cost' => 0.0, 'requests' => 0];
        }
        $this->perModel[$model]['input'] += $inputTokens;
        $this->perModel[$model]['output'] += $outputTokens;
        $this->perModel[$model]['cost'] += $cost;
        $this->perModel[$model]['requests']++;
    }

    /**
     * Record tool calls executed in the current turn.
     */
    public function recordToolCalls(int $count): void
    {
        $this->currentTurnToolCalls += $count;
        $this->totalToolCalls += $count;
    }

    /**
     * Finalize the current turn and store its record.
     */
    public function endTurn(): array
    {
        $elapsed = microtime(true) - $this->currentTurnStartTime;

        $turn = [
            'input_tokens'  => $this->currentTurnInput,
            'output_tokens' => $this->currentTurnOutput,
            'total_tokens'  => $this->currentTurnInput + $this->currentTurnOutput,
            'cost'          => $this->currentTurnCost,
            'requests'      => $this->currentTurnRequests,
            'tool_calls'    => $this->currentTurnToolCalls,
            'elapsed_ms'    => round($elapsed * 1000),
        ];

        $this->turns[] = $turn;
        return $turn;
    }

    /**
     * Get session-level summary.
     */
    public function getSessionSummary(): array
    {
        $elapsed = microtime(true) - $this->sessionStartTime;

        return [
            'input_tokens'   => $this->totalInputTokens,
            'output_tokens'  => $this->totalOutputTokens,
            'total_tokens'   => $this->totalInputTokens + $this->totalOutputTokens,
            'cost'           => $this->totalCost,
            'requests'       => $this->totalRequests,
            'tool_calls'     => $this->totalToolCalls,
            'turns'          => count($this->turns),
            'elapsed_s'      => round($elapsed, 1),
            'per_model'      => $this->perModel,
        ];
    }

    /**
     * Get the most recent turn's metrics.
     */
    public function getLastTurn(): ?array
    {
        return !empty($this->turns) ? end($this->turns) : null;
    }

    /**
     * Format a compact usage line for display after each turn.
     * Example: "847 in · 234 out · $0.0042 · 1.2s"
     */
    public function formatTurnSummary(array $turn): string
    {
        $parts = [];

        $total = $turn['total_tokens'] ?? 0;
        if ($total > 0) {
            $parts[] = $this->formatTokenCount($turn['input_tokens'] ?? 0) . ' in';
            $parts[] = $this->formatTokenCount($turn['output_tokens'] ?? 0) . ' out';
        }

        $cost = $turn['cost'] ?? 0.0;
        if ($cost > 0) {
            $parts[] = $this->formatCost($cost);
        }

        if (($turn['tool_calls'] ?? 0) > 0) {
            $n = $turn['tool_calls'];
            $parts[] = "{$n} tool" . ($n !== 1 ? 's' : '');
        }

        $elapsed = $turn['elapsed_ms'] ?? 0;
        if ($elapsed > 0) {
            $parts[] = $this->formatDuration($elapsed);
        }

        return implode(' · ', $parts);
    }

    /**
     * Format a compact session summary line.
     * Example: "Session: 12.4k tokens · $0.08 · 14 turns · 3m 22s"
     */
    public function formatSessionSummary(): string
    {
        $s = $this->getSessionSummary();
        $parts = [];

        if ($s['total_tokens'] > 0) {
            $parts[] = $this->formatTokenCount($s['total_tokens']) . ' tokens';
        }

        if ($s['cost'] > 0) {
            $parts[] = $this->formatCost($s['cost']);
        }

        $parts[] = $s['turns'] . ' turn' . ($s['turns'] !== 1 ? 's' : '');

        if ($s['tool_calls'] > 0) {
            $parts[] = $s['tool_calls'] . ' tool calls';
        }

        if ($s['elapsed_s'] > 0) {
            $parts[] = $this->formatDuration((int)($s['elapsed_s'] * 1000));
        }

        return implode(' · ', $parts);
    }

    // ── Formatting helpers ──────────────────────────────────────────

    public function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return round($tokens / 1_000_000, 1) . 'M';
        }
        if ($tokens >= 1_000) {
            return round($tokens / 1_000, 1) . 'k';
        }
        return (string)$tokens;
    }

    public function formatCost(float $cost): string
    {
        if ($cost < 0.001) {
            return '$' . number_format($cost, 4);
        }
        if ($cost < 1.0) {
            return '$' . number_format($cost, 3);
        }
        return '$' . number_format($cost, 2);
    }

    public function formatDuration(int $ms): string
    {
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        $seconds = $ms / 1000;
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }
        $minutes = (int)($seconds / 60);
        $secs = (int)($seconds % 60);
        return "{$minutes}m {$secs}s";
    }

    // ── Cost estimation ─────────────────────────────────────────────

    private function estimateCost(string $provider, string $model, int $inputTokens, int $outputTokens): float
    {
        // Free for local providers
        if (in_array($provider, self::FREE_PROVIDERS, true)) {
            return 0.0;
        }

        // Look up pricing — try exact match, then prefix match
        $pricing = self::MODEL_PRICING[$model] ?? null;

        if (!$pricing) {
            // Try prefix matching (e.g., "claude-sonnet-4-20250514" matches "claude-sonnet-4")
            foreach (self::MODEL_PRICING as $prefix => $rates) {
                if (str_starts_with($model, $prefix)) {
                    $pricing = $rates;
                    break;
                }
            }
        }

        if (!$pricing) {
            return 0.0; // Unknown model, can't estimate
        }

        [$inputPer1M, $outputPer1M] = $pricing;
        return ($inputTokens * $inputPer1M / 1_000_000) + ($outputTokens * $outputPer1M / 1_000_000);
    }

    /**
     * Get raw totals for external use.
     */
    public function getTotals(): array
    {
        return [
            'input_tokens'  => $this->totalInputTokens,
            'output_tokens' => $this->totalOutputTokens,
            'total_tokens'  => $this->totalInputTokens + $this->totalOutputTokens,
            'cost'          => $this->totalCost,
            'requests'      => $this->totalRequests,
            'tool_calls'    => $this->totalToolCalls,
        ];
    }
}
