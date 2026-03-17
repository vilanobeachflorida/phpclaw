<?php

namespace App\Libraries\Tools;

/**
 * Contract for all agent tools.
 * Every tool (file_read, shell_exec, etc.) implements this interface.
 */
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getInputSchema(): array;
    public function execute(array $args): array;
    public function getConfig(): array;
    public function isEnabled(): bool;
}
