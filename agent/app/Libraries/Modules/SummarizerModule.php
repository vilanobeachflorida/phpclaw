<?php

namespace App\Libraries\Modules;

class SummarizerModule extends BaseModule
{
    protected string $name = 'summarizer';
    protected string $description = 'Content summarization';
    protected string $role = 'summarization';

    protected function getDefaultPrompt(): string
    {
        return 'You are a summarization assistant. Provide concise, accurate summaries that preserve key information. Focus on the most important points.';
    }
}
