<?php

namespace App\Libraries\Modules;

class HeartbeatModule extends BaseModule
{
    protected string $name = 'heartbeat';
    protected string $description = 'System health monitoring';
    protected string $role = 'heartbeat';

    protected function getDefaultPrompt(): string
    {
        return 'You are a system health monitor. Respond with a brief status check confirmation.';
    }
}
