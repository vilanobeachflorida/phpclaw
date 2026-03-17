<?php

namespace App\Libraries\Modules;

class MemoryModule extends BaseModule
{
    protected string $name = 'memory';
    protected string $description = 'Memory management and compaction';
    protected string $role = 'memory_compaction';

    protected function getDefaultPrompt(): string
    {
        return 'You are a memory management assistant. Analyze conversation logs and extract key facts, decisions, and actionable information. Create concise summaries that preserve meaning while reducing size.';
    }
}
