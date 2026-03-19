<?php

namespace App\Libraries\Agent;

use App\Libraries\Service\ToolRegistry;
use App\Libraries\Memory\MemoryManager;
use App\Libraries\Storage\FileStorage;

/**
 * Builds system prompts with tool descriptions, memory context,
 * and environment info. This is what makes the model aware of
 * its tools and what it knows from previous interactions.
 */
class PromptBuilder
{
    private ToolRegistry $tools;
    private MemoryManager $memory;
    private FileStorage $storage;

    public function __construct(ToolRegistry $tools, ?FileStorage $storage = null, ?MemoryManager $memory = null)
    {
        $this->tools = $tools;
        $this->storage = $storage ?? new FileStorage();
        $this->memory = $memory ?? new MemoryManager($this->storage);
    }

    /**
     * Build the full system prompt for the agent.
     *
     * @param string $module   Current module (reasoning, coding, etc)
     * @param array  $context  Additional context: session_id, etc.
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

        // Memory context — permanent notes, session context, global summary
        $sessionId = $context['session_id'] ?? null;
        $memoryContext = $this->memory->buildPromptContext($sessionId);
        if ($memoryContext) {
            $parts[] = $memoryContext;
        }

        // Tool descriptions — filtered by module
        $allowedTools = $this->getModuleTools($module);
        $parts[] = $this->getToolInstructions($allowedTools);

        // Environment info
        $parts[] = $this->getEnvironmentInfo();

        // Response format instructions
        $parts[] = $this->getResponseFormat();

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Get the list of allowed tools for a module from modules.json.
     * Returns null if the module allows all tools (*), or an array of tool names.
     */
    private function getModuleTools(string $module): ?array
    {
        $configPath = $this->storage->path('config', 'modules.json');
        if (!file_exists($configPath)) return null;

        $config = json_decode(file_get_contents($configPath), true);
        $tools = $config['modules'][$module]['tools'] ?? null;

        if (!$tools || in_array('*', $tools, true)) {
            return null; // All tools allowed
        }

        return $tools;
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

    /**
     * Build tool instructions for the system prompt.
     *
     * @param array|null $allowedTools List of tool names to include, or null for all.
     */
    private function getToolInstructions(?array $allowedTools = null): string
    {
        $toolList = $this->tools->listAll();
        if (empty($toolList)) {
            return '';
        }

        // Filter by module's allowed tools
        if ($allowedTools !== null) {
            $toolList = array_filter($toolList, fn($t) => in_array($t['name'], $allowedTools, true));
        }

        if (empty($toolList)) {
            return '';
        }

        $lines = ["## Available Tools\n"];
        $lines[] = "You have the following tools available. To use a tool, include a tool_call block in your response:\n";
        $lines[] = "```";
        $lines[] = '<tool_call>{"name": "tool_name", "args": {"arg1": "value1"}}</tool_call>';
        $lines[] = "```\n";
        $lines[] = "You can make multiple tool calls in a single response. After each tool call, you will receive the result and can continue.\n";

        // Memory instructions (only if memory tools are available)
        $hasMemory = $allowedTools === null || in_array('memory_write', $allowedTools, true);
        if ($hasMemory) {
            $lines[] = "**Important: Use memory_write to save anything you might need to remember later** — user preferences, project details, important facts, corrections, etc. Use memory_read to recall saved information.\n";
        }

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

        // Tool usage examples — only show examples for available tools
        $lines[] = "### Examples:\n";

        $toolNames = array_column($toolList, 'name');
        $examples = [
            'file_read'    => ['Read a file:', '<tool_call>{"name": "file_read", "args": {"path": "/etc/hostname"}}</tool_call>'],
            'shell_exec'   => ['Run a command:', '<tool_call>{"name": "shell_exec", "args": {"command": "date"}}</tool_call>'],
            'file_write'   => ['Create a file:', '<tool_call>{"name": "file_write", "args": {"path": "hello.txt", "content": "Hello world"}}</tool_call>'],
            'grep_search'  => ['Search files:', '<tool_call>{"name": "grep_search", "args": {"pattern": "TODO", "path": "."}}</tool_call>'],
            'memory_write' => ['Save to memory:', '<tool_call>{"name": "memory_write", "args": {"content": "User prefers Python", "type": "permanent", "tags": "preference"}}</tool_call>'],
        ];

        $shownExamples = 0;
        foreach ($examples as $toolName => [$label, $example]) {
            if (in_array($toolName, $toolNames, true) && $shownExamples < 3) {
                $lines[] = $label;
                $lines[] = $example;
                $lines[] = '';
                $shownExamples++;
            }
        }

        return implode("\n", $lines);
    }

    private function getEnvironmentInfo(): string
    {
        $home = getenv('HOME') ?: '/home/user';
        $cwd = getcwd();
        $user = getenv('USER') ?: 'user';
        $os = PHP_OS;
        $workspace = WRITEPATH . 'agent/workspace';

        return <<<PROMPT
## Environment
- OS: {$os}
- User: {$user}
- Home: {$home}
- Working directory: {$cwd}
- Workspace: {$workspace}

**IMPORTANT: When creating files, projects, websites, or any output for the user, always put them in the workspace directory ({$workspace}) unless the user explicitly specifies a different path.** The workspace is a dedicated output folder that is git-ignored and can be safely cleaned. Create project subdirectories inside it, e.g. {$workspace}/my-project/.
PROMPT;
    }

    private function getResponseFormat(): string
    {
        return <<<'PROMPT'
## Response Guidelines

### Action Over Narration
- ALWAYS use tool_call to take action. NEVER describe what you would do — just do it.
- WRONG: "I'll create index.php with the following content..."
- RIGHT: <tool_call>{"name": "file_write", "args": {"path": "index.php", "content": "..."}}</tool_call>
- Every response should contain at least one tool_call unless the task is fully complete.

### Completing Tasks
- Do NOT stop after partial work. If you created directories, you MUST also create the files.
- After tool results come back, immediately make your next tool_call. Don't summarize progress.
- Only provide a final text response (no tool_call) when ALL work is done.
- If a tool fails, try an alternative approach instead of giving up.

### Format Rules
- Do NOT wrap your thinking in <think> tags or output internal reasoning.
- Keep text responses brief. Lead with actions, follow with short explanations.
- Do NOT refuse tasks by saying you lack filesystem access — you have tools for that.
- Save important information with memory_write for future reference.

### Working With Tool Results
- When you receive tool results, read them and take the next action immediately.
- Don't repeat what the tool results say — the user can see the tool status.
- Focus on making progress: read result → next tool_call → read result → next tool_call.
PROMPT;
    }
}
