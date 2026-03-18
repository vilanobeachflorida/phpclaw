<?php

namespace App\Libraries\Agent;

/**
 * Parses LLM responses to extract tool calls, strip thinking/reasoning,
 * and separate user-visible content from agent control flow.
 *
 * Handles messy real-world LLM output:
 * - Tool calls in multiple formats (XML, markdown, JSON, bare)
 * - Unclosed tool call tags
 * - Internal reasoning that shouldn't be shown to users
 * - Mixed tool calls and display text in one response
 */
class ResponseParser
{
    /**
     * Parse a raw LLM response into structured parts.
     */
    public function parse(string $raw): array
    {
        $cleaned = $this->stripThinkingTags($raw);
        $toolCalls = $this->extractToolCalls($cleaned);
        $displayText = $this->extractDisplayText($cleaned, !empty($toolCalls));

        return [
            'raw' => $raw,
            'display' => trim($displayText),
            'tool_calls' => $toolCalls,
            'has_tool_calls' => !empty($toolCalls),
        ];
    }

    /**
     * Strip thinking/reasoning tags and their content.
     * Handles: <think>, <thinking>, <reasoning>, <internal>, <reflection>
     * Also handles unclosed tags (model started but didn't close).
     */
    public function stripThinkingTags(string $text): string
    {
        $tags = ['think', 'thinking', 'reasoning', 'internal', 'reflection', 'analysis'];

        foreach ($tags as $tag) {
            // Closed tags
            $text = preg_replace("/<{$tag}>.*?<\/{$tag}>/is", '', $text);
            // Unclosed tags (model started thinking but didn't close)
            $text = preg_replace("/<{$tag}>.*$/is", '', $text);
        }

        return trim($text);
    }

    /**
     * Extract tool calls from model output.
     * Supports multiple formats that LLMs commonly produce:
     *
     * 1. <tool_call>{"name": "x", "args": {...}}</tool_call>
     * 2. ```tool_call\n{"name": "x", "args": {...}}\n```
     * 3. [TOOL: name(key="val")]
     * 4. Bare JSON with tool call structure: {"name": "x", "args": {...}}
     * 5. Inline <tool_call>JSON (no closing tag)
     */
    public function extractToolCalls(string $text): array
    {
        $calls = [];

        // Format 1: <tool_call>JSON</tool_call> (with or without whitespace)
        if (preg_match_all('/<tool_call>\s*(.*?)\s*<\/tool_call>/is', $text, $matches)) {
            foreach ($matches[1] as $json) {
                $parsed = $this->parseToolCallJson($json);
                if ($parsed) $calls[] = $parsed;
            }
        }

        // Format 1b: <tool_call>JSON without closing tag (common with streaming)
        if (preg_match_all('/<tool_call>\s*(\{[^<]*?"name"\s*:\s*"[^"]+?"[^<]*?\})\s*(?!<\/tool_call>)/is', $text, $matches)) {
            foreach ($matches[1] as $json) {
                // Don't double-count if already matched by Format 1
                $parsed = $this->parseToolCallJson($json);
                if ($parsed && !$this->isDuplicate($calls, $parsed)) {
                    $calls[] = $parsed;
                }
            }
        }

        // Format 2: ```tool_call ... ``` or ```json ... ``` containing tool call
        if (preg_match_all('/```(?:tool_call|json)\s*\n(.*?)\n```/is', $text, $matches)) {
            foreach ($matches[1] as $json) {
                $parsed = $this->parseToolCallJson($json);
                if ($parsed && !$this->isDuplicate($calls, $parsed)) {
                    $calls[] = $parsed;
                }
            }
        }

        // Format 3: [TOOL: name(key="val", ...)]
        if (preg_match_all('/\[TOOL:\s*(\w+)\((.*?)\)\]/is', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                $argsStr = $match[2];
                $args = $this->parseKeyValueArgs($argsStr);
                if ($name && $args !== null) {
                    $parsed = ['name' => $name, 'args' => $args];
                    if (!$this->isDuplicate($calls, $parsed)) {
                        $calls[] = $parsed;
                    }
                }
            }
        }

        // Format 4: Bare JSON objects that look like tool calls (not inside tags)
        // Only if we haven't found any calls yet via other formats
        if (empty($calls)) {
            if (preg_match_all('/\{\s*"name"\s*:\s*"(\w+)"\s*,\s*"(?:args|arguments|parameters)"\s*:\s*(\{.*?\})\s*\}/is', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $fullJson = $match[0];
                    $parsed = $this->parseToolCallJson($fullJson);
                    if ($parsed) $calls[] = $parsed;
                }
            }
        }

        return $calls;
    }

    /**
     * Remove tool call markup and internal reasoning from text to get user-visible content.
     *
     * When tool calls are present, we aggressively strip reasoning text
     * since the model is "working" and the user just needs to see results.
     */
    public function extractDisplayText(string $text, bool $hasToolCalls = false): string
    {
        // Remove all tool call formats
        $text = preg_replace('/<tool_call>.*?<\/tool_call>/is', '', $text);
        $text = preg_replace('/<tool_call>\s*\{.*$/ims', '', $text); // unclosed
        $text = preg_replace('/```(?:tool_call|json)\s*\n.*?\n```/is', '', $text);
        $text = preg_replace('/\[TOOL:\s*\w+\(.*?\)\]/is', '', $text);

        // Remove bare JSON tool calls
        $text = preg_replace('/\{\s*"name"\s*:\s*"(\w+)"\s*,\s*"(?:args|arguments|parameters)"\s*:\s*\{.*?\}\s*\}/is', '', $text);

        // Remove tool result blocks that sometimes leak
        $text = preg_replace('/<tool_result.*?<\/tool_result>/is', '', $text);

        // If there are tool calls, strip internal reasoning patterns
        // These are things the model says to itself while working
        if ($hasToolCalls) {
            $text = $this->stripInternalReasoning($text);
        }

        // Clean up whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Strip common internal reasoning patterns from display text.
     * These are phrases models use when "thinking out loud" before tool calls.
     */
    private function stripInternalReasoning(string $text): string
    {
        $lines = explode("\n", $text);
        $filtered = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines at the start
            if (empty($trimmed) && empty($filtered)) continue;

            // Skip lines that look like internal reasoning
            if ($this->isInternalReasoning($trimmed)) continue;

            $filtered[] = $line;
        }

        return implode("\n", $filtered);
    }

    /**
     * Detect if a line is internal model reasoning that shouldn't be shown.
     */
    private function isInternalReasoning(string $line): bool
    {
        if (empty($line)) return false;

        // Common reasoning prefixes
        $reasoningPrefixes = [
            'the user wants',
            'the user is asking',
            'the user asked',
            'i need to',
            'i should',
            'i\'ll need to',
            'i will need to',
            'let me ',
            'i can see',
            'i see that',
            'i see the',
            'looking at',
            'i notice',
            'first, i',
            'first i',
            'now i need',
            'now i\'ll',
            'now let me',
            'to do this',
            'to accomplish this',
            'my approach',
            'my plan',
            'here\'s my plan',
            'here is my plan',
            'i\'m going to',
            'i am going to',
        ];

        $lower = strtolower($line);
        foreach ($reasoningPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        // Lines that are just about what URLs to fetch or files to read
        if (preg_match('/^(?:\d+\.\s*)?https?:\/\/\S+\s*\(/', $line)) return true;

        return false;
    }

    /**
     * Parse a JSON tool call object. Handles various key names.
     */
    private function parseToolCallJson(string $json): ?array
    {
        $json = trim($json);

        // Fix common JSON issues from LLMs
        // Remove trailing commas before } or ]
        $json = preg_replace('/,\s*([\]}])/', '$1', $json);

        $data = json_decode($json, true);

        if (!$data || !isset($data['name'])) {
            return null;
        }

        return [
            'name' => $data['name'],
            'args' => $data['args'] ?? $data['arguments'] ?? $data['parameters'] ?? $data['input'] ?? [],
        ];
    }

    /**
     * Check if a tool call is a duplicate of one already in the list.
     */
    private function isDuplicate(array $existing, array $new): bool
    {
        foreach ($existing as $call) {
            if ($call['name'] === $new['name'] && json_encode($call['args']) === json_encode($new['args'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse key="value" argument pairs from function-call style syntax.
     */
    private function parseKeyValueArgs(string $str): ?array
    {
        $args = [];
        if (preg_match_all('/(\w+)\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/s', $str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $args[$m[1]] = stripcslashes($m[2]);
            }
        }
        if (preg_match_all("/(\w+)\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/s", $str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $args[$m[1]] = stripcslashes($m[2]);
            }
        }
        return $args;
    }
}
