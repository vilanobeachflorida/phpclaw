<?php

namespace App\Libraries\Agent;

use App\Libraries\Service\ToolRegistry;
use App\Libraries\Storage\FileStorage;

/**
 * Builds system prompts that describe available tools to the LLM.
 * This is what makes the model tool-aware - it instructs the model on
 * what tools exist, how to invoke them, and the expected format.
 */
class PromptBuilder
{
    private ToolRegistry $tools;
    private FileStorage $storage;

    public function __construct(ToolRegistry $tools, ?FileStorage $storage = null)
    {
        $this->tools = $tools;
        $this->storage = $storage ?? new FileStorage();
    }

    /**
     * Build the full system prompt for the agent.
     */
    public function buildSystemPrompt(string $module = 'reasoning', array $context = []): string
    {
        $parts = [];

        // Core identity
        $parts[] = $this->getCoreIdentity();

        // Module-specific prompt
        $modulePrompt = $this->getModulePrompt($module);
        if ($modulePrompt) {
            $parts[] = $modulePrompt;
        }

        // Tool descriptions
        $parts[] = $this->getToolInstructions();

        // Environment info
        $parts[] = $this->getEnvironmentInfo();

        // Response format instructions
        $parts[] = $this->getResponseFormat();

        return implode("\n\n", array_filter($parts));
    }

    private function getCoreIdentity(): string
    {
        return <<<'PROMPT'
You are PHPClaw, a terminal-native AI agent assistant. You are running as a local agent shell on the user's machine. You have direct access to the user's filesystem and can execute commands on their behalf.

You are NOT a cloud-based assistant with restrictions. You ARE a local agent with real capabilities. When a user asks you to do something (create files, run commands, search files, fetch URLs), you MUST use your tools to actually do it. Never say "I can't access your filesystem" - you CAN and SHOULD use your tools.

Be concise, action-oriented, and helpful. Execute tasks directly rather than explaining how the user could do them manually.
PROMPT;
    }

    private function getModulePrompt(string $module): ?string
    {
        $path = $this->storage->path('prompts', 'modules', $module . '.md');
        if (file_exists($path)) {
            return trim(file_get_contents($path));
        }
        return null;
    }

    private function getToolInstructions(): string
    {
        $toolList = $this->tools->listAll();
        if (empty($toolList)) {
            return '';
        }

        $lines = ["## Available Tools\n"];
        $lines[] = "You have the following tools available. To use a tool, include a tool_call block in your response:\n";
        $lines[] = "```";
        $lines[] = '<tool_call>{"name": "tool_name", "args": {"arg1": "value1"}}</tool_call>';
        $lines[] = "```\n";
        $lines[] = "You can make multiple tool calls in a single response. After each tool call, you will receive the result and can continue.\n";
        $lines[] = "### Tools:\n";

        foreach ($toolList as $tool) {
            if (!$tool['enabled']) continue;

            $lines[] = "**{$tool['name']}**: {$tool['description']}";

            if (!empty($tool['schema'])) {
                $args = [];
                foreach ($tool['schema'] as $argName => $argDef) {
                    $req = ($argDef['required'] ?? false) ? 'required' : 'optional';
                    $type = $argDef['type'] ?? 'string';
                    $desc = $argDef['description'] ?? '';
                    $args[] = "  - `{$argName}` ({$type}, {$req})" . ($desc ? ": {$desc}" : '');
                }
                if ($args) {
                    $lines[] = implode("\n", $args);
                }
            }
            $lines[] = '';
        }

        // Tool usage examples
        $lines[] = "### Examples:\n";
        $lines[] = 'Create a file:';
        $lines[] = '<tool_call>{"name": "file_write", "args": {"path": "/home/user/hello.txt", "content": "Hello World!"}}</tool_call>';
        $lines[] = '';
        $lines[] = 'Read a file:';
        $lines[] = '<tool_call>{"name": "file_read", "args": {"path": "/etc/hostname"}}</tool_call>';
        $lines[] = '';
        $lines[] = 'Run a command:';
        $lines[] = '<tool_call>{"name": "shell_exec", "args": {"command": "date"}}</tool_call>';
        $lines[] = '';
        $lines[] = 'Search files:';
        $lines[] = '<tool_call>{"name": "grep_search", "args": {"pattern": "TODO", "path": "/home/user/project"}}</tool_call>';
        $lines[] = '';
        $lines[] = 'List directory:';
        $lines[] = '<tool_call>{"name": "dir_list", "args": {"path": "/home/user"}}</tool_call>';
        $lines[] = '';
        $lines[] = 'Fetch a URL:';
        $lines[] = '<tool_call>{"name": "http_get", "args": {"url": "https://api.example.com/data"}}</tool_call>';

        return implode("\n", $lines);
    }

    private function getEnvironmentInfo(): string
    {
        $home = getenv('HOME') ?: '/home/user';
        $cwd = getcwd();
        $user = getenv('USER') ?: 'user';
        $os = PHP_OS;

        return <<<PROMPT
## Environment
- OS: {$os}
- User: {$user}
- Home: {$home}
- Working directory: {$cwd}
PROMPT;
    }

    private function getResponseFormat(): string
    {
        return <<<'PROMPT'
## Response Guidelines
- When the user asks you to perform an action, USE YOUR TOOLS. Do not describe what you would do - actually do it.
- After using a tool, briefly report the result to the user.
- You can chain multiple tool calls in one response if needed.
- If a tool fails, explain the error and try an alternative approach.
- Do NOT wrap your thinking in <think> tags or output internal reasoning. Just respond directly.
- Keep responses concise. Lead with actions, follow with brief explanations only if needed.
- Do NOT refuse tasks by saying you lack filesystem access - you have tools for that.
PROMPT;
    }
}
