<?php

namespace App\Libraries\Agent;

/**
 * Parses LLM responses to extract tool calls, strip thinking tags,
 * and separate user-visible content from agent control flow.
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
        $displayText = $this->extractDisplayText($cleaned);

        return [
            'raw' => $raw,
            'display' => trim($displayText),
            'tool_calls' => $toolCalls,
            'has_tool_calls' => !empty($toolCalls),
        ];
    }

    /**
     * Strip <think>...</think> and similar reasoning tags from output.
     * Handles: <think>, <thinking>, <reasoning>, <internal>
     */
    public function stripThinkingTags(string $text): string
    {
        $patterns = [
            '/<think>.*?<\/think>/is',
            '/<thinking>.*?<\/thinking>/is',
            '/<reasoning>.*?<\/reasoning>/is',
            '/<internal>.*?<\/internal>/is',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        // Also strip incomplete think tags (model started thinking but didn't close)
        $text = preg_replace('/<think>.*$/is', '', $text);
        $text = preg_replace('/<thinking>.*$/is', '', $text);

        return trim($text);
    }

    /**
     * Extract tool calls from model output.
     * Supports multiple formats:
     *
     * Format 1 - XML-style:
     *   <tool_call>{"name": "file_write", "args": {"path": "/tmp/test.txt", "content": "hello"}}</tool_call>
     *
     * Format 2 - Markdown code block:
     *   ```tool_call
     *   {"name": "file_write", "args": {"path": "/tmp/test.txt", "content": "hello"}}
     *   ```
     *
     * Format 3 - Function call style:
     *   [TOOL: file_write(path="/tmp/test.txt", content="hello")]
     */
    public function extractToolCalls(string $text): array
    {
        $calls = [];

        // Format 1: <tool_call>JSON</tool_call>
        if (preg_match_all('/<tool_call>\s*(.*?)\s*<\/tool_call>/is', $text, $matches)) {
            foreach ($matches[1] as $json) {
                $parsed = $this->parseToolCallJson($json);
                if ($parsed) $calls[] = $parsed;
            }
        }

        // Format 2: ```tool_call ... ```
        if (preg_match_all('/```tool_call\s*\n(.*?)\n```/is', $text, $matches)) {
            foreach ($matches[1] as $json) {
                $parsed = $this->parseToolCallJson($json);
                if ($parsed) $calls[] = $parsed;
            }
        }

        // Format 3: [TOOL: name(key="val", ...)]
        if (preg_match_all('/\[TOOL:\s*(\w+)\((.*?)\)\]/is', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                $argsStr = $match[2];
                $args = $this->parseKeyValueArgs($argsStr);
                if ($name && $args !== null) {
                    $calls[] = ['name' => $name, 'args' => $args];
                }
            }
        }

        return $calls;
    }

    /**
     * Remove tool call markup from text to get display-only content.
     */
    public function extractDisplayText(string $text): string
    {
        // Remove tool call blocks
        $text = preg_replace('/<tool_call>.*?<\/tool_call>/is', '', $text);
        $text = preg_replace('/```tool_call\s*\n.*?\n```/is', '', $text);
        $text = preg_replace('/\[TOOL:\s*\w+\(.*?\)\]/is', '', $text);

        // Clean up extra whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Parse a JSON tool call object.
     */
    private function parseToolCallJson(string $json): ?array
    {
        $json = trim($json);
        $data = json_decode($json, true);

        if (!$data || !isset($data['name'])) {
            return null;
        }

        return [
            'name' => $data['name'],
            'args' => $data['args'] ?? $data['arguments'] ?? $data['parameters'] ?? [],
        ];
    }

    /**
     * Parse key="value" argument pairs from function-call style syntax.
     */
    private function parseKeyValueArgs(string $str): ?array
    {
        $args = [];
        // Match key="value" or key='value' patterns
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
