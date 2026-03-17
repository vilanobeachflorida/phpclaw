# Development Guide

## Project Structure

```
agent/
  app/
    Commands/Agent/     # Spark CLI commands
    Libraries/
      Cache/            # File-based cache manager
      Memory/           # Memory ingestion and compaction
      Modules/          # Agent modules (reasoning, coding, etc.)
      Providers/        # LLM provider adapters
      Router/           # Model routing
      Service/          # Service loop, provider manager, tool registry
      Session/          # Session management
      Storage/          # File storage layer, config loader
      Tasks/            # Background task manager
      Tools/            # Agent tools (file ops, http, shell, etc.)
  writable/agent/       # All runtime data (file-based)
  templates/            # Scaffolding templates
docs/                   # Documentation
```

## Adding a New Tool

1. Scaffold:
   ```bash
   php spark agent:tool:scaffold my_tool
   ```

2. Edit `app/Libraries/Tools/MyToolTool.php`:
   - Define `getInputSchema()` with expected arguments
   - Implement `execute()` method
   - Return `$this->success($data)` or `$this->error($message)`

3. Register in `writable/agent/config/tools.json`:
   ```json
   "my_tool": { "enabled": true, "description": "My custom tool", "timeout": 10 }
   ```

4. Add to `app/Libraries/Service/ToolRegistry.php` builtinTools map.

## Adding a New Provider

1. Scaffold:
   ```bash
   php spark agent:provider:scaffold my_provider
   ```

2. Edit `app/Libraries/Providers/MyProviderProvider.php`:
   - Implement `healthCheck()`, `listModels()`, `chat()`
   - Use `$this->httpRequest()` for API calls
   - Return `$this->successResponse()` or `$this->errorResponse()`

3. Register in `writable/agent/config/providers.json`:
   ```json
   "my_provider": { "enabled": true, "type": "my_provider", ... }
   ```

4. Add to `app/Libraries/Service/ProviderManager.php` typeMap.

## Adding a New Module

1. Create `app/Libraries/Modules/MyModule.php` extending `BaseModule`
2. Set name, description, role
3. Implement `getDefaultPrompt()`
4. Add prompt file to `writable/agent/prompts/modules/my_module.md`
5. Register in `writable/agent/config/modules.json`

## Config Files

All config is in `writable/agent/config/`:
- `app.json` - Global settings
- `providers.json` - Provider configurations
- `roles.json` - Role-to-provider/model mapping
- `modules.json` - Module configurations
- `tools.json` - Tool enable/disable and settings
- `service.json` - Service loop configuration

## Testing Locally

```bash
cd agent

# Check commands are registered
php spark list

# Test config loading
php spark agent:config app

# Test provider health
php spark agent:providers

# Start interactive chat
php spark agent:chat

# Start service loop
php spark agent:serve
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Follow existing code patterns (tools extend BaseTool, providers extend BaseProvider)
4. Keep it simple - prefer boring code over clever code
5. File-based storage only - no databases
6. Submit a pull request
