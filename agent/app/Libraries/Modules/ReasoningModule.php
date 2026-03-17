<?php

namespace App\Libraries\Modules;

class ReasoningModule extends BaseModule
{
    protected string $name = 'reasoning';
    protected string $description = 'Deep reasoning and analysis';
    protected string $role = 'reasoning';

    protected function getDefaultPrompt(): string
    {
        return 'You are an expert reasoning assistant. Think step-by-step, analyze problems carefully, and provide well-structured responses.';
    }
}
