<?php

namespace App\Libraries\UI;

/**
 * No-op UI adapter for non-interactive contexts (API, background tasks).
 * All output methods are silent. Interactive methods return safe defaults.
 */
class NullUI extends TerminalUI
{
    public function write(string $text, string ...$styles): void {}
    public function writeln(string $text = '', string ...$styles): void {}
    public function inline(string $text, string ...$styles): void {}
    public function newLine(int $count = 1): void {}
    public function clearLine(): void {}
    public function cursorUp(int $lines = 1): void {}
    public function cursorDown(int $lines = 1): void {}
    public function hideCursor(): void {}
    public function showCursor(): void {}
    public function thinking(string $message = 'Thinking'): void {}
    public function thinkingDone(): void {}
    public function dim(string $text): void {}
    public function info(string $text): void {}
    public function error(string $text): void {}
    public function warn(string $text): void {}
    public function success(string $text): void {}
    public function warnBox(string ...$lines): void {}
    public function toolCall(string $name, bool $success, string $detail = ''): void {}
    public function agentResponse(string $text): void {}
    public function hr(string $color = 'gray'): void {}

    /**
     * Interactive menu — return 2 (Stop) to prevent blocking.
     */
    public function menu(string $title, array $options, int $default = 0): ?int
    {
        return 2;
    }

    /**
     * Interactive prompt — return empty to prevent blocking.
     */
    public function prompt(string $label, string $default = '', bool $secret = false): ?string
    {
        return $default;
    }
}
