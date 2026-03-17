<?php

namespace App\Libraries\Modules;

class ToolRouterModule extends BaseModule
{
    protected string $name = 'tool_router';
    protected string $description = 'Routes tool execution requests';
    protected string $role = 'fast_response';

    protected function getDefaultPrompt(): string
    {
        return 'You are a tool routing assistant. Determine which tools to use for a given request and coordinate their execution.';
    }
}
