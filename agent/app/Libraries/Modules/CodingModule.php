<?php

namespace App\Libraries\Modules;

class CodingModule extends BaseModule
{
    protected string $name = 'coding';
    protected string $description = 'Code generation and modification';
    protected string $role = 'coding';

    protected function getDefaultPrompt(): string
    {
        return 'You are an expert software engineer. Write clean, well-structured code. Follow best practices and explain your approach when needed.';
    }
}
