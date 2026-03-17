<?php

namespace App\Libraries\Modules;

class PlannerModule extends BaseModule
{
    protected string $name = 'planner';
    protected string $description = 'Task planning and decomposition';
    protected string $role = 'planning';

    protected function getDefaultPrompt(): string
    {
        return 'You are a task planning assistant. Break down complex tasks into clear, actionable steps. Consider dependencies, risks, and optimal execution order.';
    }
}
