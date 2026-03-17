# Tools

## Overview

Tools are callable actions that the agent can invoke during chat and task execution. PHPClaw provides a set of built-in tools and supports adding custom tools through scaffolding.

## ToolInterface and BaseTool

All tools implement `ToolInterface`:

```php
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getParameters(): array;
    public function execute(array $params): array;
}
```

The `BaseTool` abstract class provides common functionality:

```php
abstract class BaseTool implements ToolInterface
{
    protected string $name;
    protected string $description;

    public function getName(): string;
    public function getDescription(): string;
    abstract public function getParameters(): array;
    abstract public function execute(array $params): array;

    protected function success(mixed $data, string $message = ''): array;
    protected function error(string $message, mixed $details = null): array;
}
```

## Built-in Tools

| Tool | Description |
|---|---|
| `file_read` | Read the contents of a file |
| `file_write` | Write content to a file |
| `file_list` | List files in a directory |
| `file_search` | Search for files matching a pattern |
| `shell_exec` | Execute a shell command |
| `web_fetch` | Fetch content from a URL |
| `grep` | Search file contents with regex patterns |
| `memory_query` | Query the memory system |
| `task_create` | Create a background task |
| `calculator` | Evaluate mathematical expressions |

## Tool Execution Lifecycle

1. **Discovery** -- The ToolRegistry scans for tool classes and registers them at startup.
2. **Declaration** -- When sending a prompt to a provider, available tools are declared in the request using each tool's name, description, and parameter schema.
3. **Invocation** -- When the AI model responds with a tool call, the ToolRegistry looks up the tool by name.
4. **Validation** -- Parameters are validated against the tool's parameter schema.
5. **Execution** -- The tool's `execute()` method is called with validated parameters.
6. **Result** -- The tool returns a result array (success or error).
7. **Feedback** -- The result is sent back to the model as a tool result message for the next turn.

## Adding Custom Tools

### Scaffold a New Tool

```bash
php spark agent:tool:scaffold MyCustomTool
```

This generates a tool class from the template at `writable/agent/templates/tool.php.tpl`.

### Tool Template Structure

The generated tool class follows this structure:

```php
<?php

namespace App\Libraries\Agent\Tools;

class MyCustomTool extends BaseTool
{
    protected string $name = 'my_custom_tool';
    protected string $description = 'Description of what this tool does';

    public function getParameters(): array
    {
        return [
            'param_name' => [
                'type' => 'string',
                'description' => 'What this parameter does',
                'required' => true,
            ],
        ];
    }

    public function execute(array $params): array
    {
        // Implementation here

        return $this->success($result, 'Operation completed');
    }
}
```

### Register the Tool

After implementing, ensure the tool is discoverable by placing it in the tools directory. The ToolRegistry will auto-discover classes that extend `BaseTool`.

## Result Format

### Success

```php
$this->success($data, 'Operation completed');
```

Produces:

```json
{
  "status": "success",
  "data": "...",
  "message": "Operation completed"
}
```

### Error

```php
$this->error('File not found', ['path' => '/missing/file.txt']);
```

Produces:

```json
{
  "status": "error",
  "message": "File not found",
  "details": {
    "path": "/missing/file.txt"
  }
}
```
