# Tools

Tools are executable capabilities that the agent can invoke during conversations and task processing. PHPClaw provides a set of built-in tools and supports custom tools through scaffolding.

## ToolInterface

All tools implement `ToolInterface`, which defines:

```php
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getParameters(): array;
    public function execute(array $params): array;
    public function validate(array $params): bool;
}
```

## BaseTool

`BaseTool` is an abstract class that implements common functionality:

- Parameter validation against a schema
- Standardized result formatting
- Error handling and reporting
- Logging of tool invocations

Custom tools should extend `BaseTool` rather than implementing `ToolInterface` directly.

## Built-in Tools

| Tool | Description |
|---|---|
| `file_read` | Read the contents of a file |
| `file_write` | Write content to a file |
| `file_list` | List files in a directory |
| `file_search` | Search for files matching a pattern |
| `shell_exec` | Execute a shell command |
| `web_fetch` | Fetch content from a URL |
| `grep` | Search file contents with regex |
| `json_query` | Query JSON data with path expressions |
| `template_render` | Render a template with variables |
| `task_create` | Create a background task |
| `memory_search` | Search memory for relevant information |

## Tool Execution Lifecycle

```
Agent decides to use a tool
    │
    ▼
ToolRegistry looks up tool by name
    │
    ▼
Parameters validated against schema
    │
    ▼
tool.execute(params) called
    │
    ▼
Result returned (success or error payload)
    │
    ▼
Result included in agent response
```

1. The model's response includes a tool invocation with parameters.
2. The ToolRegistry resolves the tool by name.
3. Parameters are validated using the tool's parameter schema. Invalid parameters produce an error result without executing the tool.
4. The tool's `execute()` method is called with validated parameters.
5. The tool returns a result array.
6. The result is fed back into the conversation context for the model to interpret.

## Adding Custom Tools

### Using the Scaffold Command

```bash
php spark agent:tool:scaffold MyCustomTool
```

This generates a new tool file from the template with the correct structure and boilerplate.

### Tool Template Structure

The generated tool file follows this structure:

```php
<?php

namespace App\Libraries\Agent\Tools;

class MyCustomTool extends BaseTool
{
    protected string $name = 'my_custom_tool';
    protected string $description = 'Description of what this tool does';

    protected array $parameters = [
        'param1' => [
            'type' => 'string',
            'description' => 'Description of param1',
            'required' => true,
        ],
    ];

    public function execute(array $params): array
    {
        // Implementation here

        return $this->success(['result' => $output]);
    }
}
```

After creating the tool file, it is automatically discovered by the ToolRegistry on the next agent startup.

## Result Format

### Success

```php
$this->success([
    'result' => 'The output data',
    'metadata' => ['key' => 'value'],
]);
```

Produces:

```json
{
  "status": "success",
  "data": {
    "result": "The output data",
    "metadata": {"key": "value"}
  }
}
```

### Error

```php
$this->error('Description of what went wrong');
```

Produces:

```json
{
  "status": "error",
  "error": "Description of what went wrong"
}
```

Tools should always return a result via `$this->success()` or `$this->error()`. Unhandled exceptions are caught by the ToolRegistry and converted to error results automatically.
