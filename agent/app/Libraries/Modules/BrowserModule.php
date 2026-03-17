<?php

namespace App\Libraries\Modules;

class BrowserModule extends BaseModule
{
    protected string $name = 'browser';
    protected string $description = 'Web content fetching and processing';
    protected string $role = 'browser';

    protected function getDefaultPrompt(): string
    {
        return 'You are a web content processing assistant. Analyze fetched web content and extract relevant information.';
    }
}
