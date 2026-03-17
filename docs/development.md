# Development Guide

## Project Structure

```
phpclaw/
├── agent/                          # CodeIgniter 4 application
│   ├── app/
│   │   ├── Commands/               # Spark CLI commands
│   │   │   ├── AgentChat.php
│   │   │   ├── AgentConfig.php
│   │   │   ├── AgentServe.php
│   │   │   ├── AgentStatus.php
│   │   │   ├── AgentProviders.php
│   │   │   ├── AgentModels.php
│   │   │   ├── AgentRoles.php
│   │   │   ├── AgentModules.php
│   │   │   ├── AgentSessions.php
│   │   │   ├── AgentSessionShow.php
│   │   │   ├── AgentTasks.php
│   │   │   ├── AgentTaskShow.php
│   │   │   ├── AgentTaskTail.php
│   │   │   ├── AgentTaskCancel.php
│   │   │   ├── AgentMemoryShow.php
│   │   │   ├── AgentMemoryCompact.php
│   │   │   ├── AgentMaintain.php
│   │   │   ├── AgentCacheStatus.php
│   │   │   ├── AgentCacheClear.php
│   │   │   ├── AgentCachePrune.php
│   │   │   ├── AgentTools.php
│   │   │   ├── AgentToolScaffold.php
│   │   │   └── AgentProviderScaffold.php
│   │   ├── Libraries/
│   │   │   └── Agent/
│   │   │       ├── Core/
│   │   │       │   ├── FileStorage.php
│   │   │       │   ├── ConfigLoader.php
│   │   │       │   └── ServiceLoop.php
│   │   │       ├── Managers/
│   │   │       │   ├── SessionManager.php
│   │   │       │   ├── TaskManager.php
│   │   │       │   ├── MemoryManager.php
│   │   │       │   └── CacheManager.php
│   │   │       ├── Routing/
│   │   │       │   ├── ModelRouter.php
│   │   │       │   └── ProviderManager.php
│   │   │       ├── Tools/
│   │   │       │   ├── ToolInterface.php
│   │   │       │   ├── BaseTool.php
│   │   │       │   ├── ToolRegistry.php
│   │   │       │   ├── FileReadTool.php
│   │   │       │   ├── FileWriteTool.php
│   │   │       │   ├── ShellExecTool.php
│   │   │       │   └── ...
│   │   │       ├── Providers/
│   │   │       │   ├── ProviderInterface.php
│   │   │       │   ├── BaseProvider.php
│   │   │       │   ├── OllamaProvider.php
│   │   │       │   ├── OpenLLMProvider.php
│   │   │       │   ├── ChatGPTProvider.php
│   │   │       │   └── ClaudeCodeProvider.php
│   │   │       └── Modules/
│   │   │           ├── BaseModule.php
│   │   │           ├── HeartbeatModule.php
│   │   │           ├── ReasoningModule.php
│   │   │           ├── CodingModule.php
│   │   │           └── ...
│   │   └── Config/
│   ├── writable/
│   │   └── agent/                  # Runtime data (see storage.md)
│   ├── public/
│   ├── spark                       # CLI entry point
│   ├── .env.example
│   └── composer.json
├── docs/                           # Documentation
├── phpclaw.service                 # systemd service file
├── README.md
└── LICENSE
```

## Adding a New Tool

### 1. Scaffold

```bash
php spark agent:tool:scaffold MyTool
```

This creates `app/Libraries/Agent/Tools/MyTool.php` from the tool template.

### 2. Implement

Edit the generated file:

```php
<?php

namespace App\Libraries\Agent\Tools;

class MyTool extends BaseTool
{
    protected string $name = 'my_tool';
    protected string $description = 'What this tool does';

    public function getParameters(): array
    {
        return [
            'input' => [
                'type' => 'string',
                'description' => 'The input to process',
                'required' => true,
            ],
        ];
    }

    public function execute(array $params): array
    {
        $input = $params['input'];

        // Your implementation here
        $result = $this->processInput($input);

        return $this->success($result);
    }

    private function processInput(string $input): string
    {
        // ...
    }
}
```

### 3. Register

The ToolRegistry auto-discovers tool classes in the `Tools/` directory that extend `BaseTool`. No additional registration step is needed.

To make the tool available to a module, add its name to the module's `tools` array in `modules.json`.

## Adding a New Provider

### 1. Scaffold

```bash
php spark agent:provider:scaffold MyProvider
```

This creates `app/Libraries/Agent/Providers/MyProvider.php` from the provider template.

### 2. Implement

Edit the generated file to implement `healthCheck()`, `listModels()`, and `chat()`.

### 3. Register

Add the provider to `writable/agent/config/providers.json`:

```json
{
  "my_provider": {
    "type": "my_provider",
    "base_url": "http://localhost:5000",
    "enabled": true
  }
}
```

Then assign the provider to roles in `roles.json` as needed.

## Adding a New Module

1. Create a module class in `app/Libraries/Agent/Modules/` extending `BaseModule`.
2. Create a prompt file in `writable/agent/prompts/modules/`.
3. Add the module configuration to `writable/agent/config/modules.json`.
4. Optionally create a new role in `roles.json` or reuse an existing one.

## Config Files

All configuration lives in `writable/agent/config/`:

| File | Purpose |
|---|---|
| `providers.json` | Provider definitions, endpoints, credentials |
| `roles.json` | Role-to-provider/model mappings |
| `modules.json` | Module definitions and policies |
| `service.json` | Service loop timing and behavior |

Config files are JSON. Edit them directly -- there is no config UI.

## Testing Locally

### Prerequisites

1. PHP 8.2+ with curl extension.
2. At least one provider configured (Ollama is recommended for local development).

### Basic Workflow

```bash
# Verify configuration
php spark agent:config

# Check provider connectivity
php spark agent:providers

# List available models
php spark agent:models

# Start a chat session
php spark agent:chat

# Test the service loop
php spark agent:serve
```

### Debugging

Use the `/debug` slash command in chat to toggle verbose output. This shows the raw messages sent to and received from providers, tool call details, and routing decisions.

## Contributing Guidelines

1. Follow PSR-12 coding standards for PHP.
2. Keep commands thin -- business logic belongs in libraries.
3. All state changes go through the appropriate manager class.
4. Use FileStorage for all file I/O -- no direct `file_get_contents()` / `file_put_contents()`.
5. New tools must implement `ToolInterface` (preferably extend `BaseTool`).
6. New providers must implement `ProviderInterface` (preferably extend `BaseProvider`).
7. Document new features in the appropriate docs file.
8. Test with at least one local provider before submitting changes.
