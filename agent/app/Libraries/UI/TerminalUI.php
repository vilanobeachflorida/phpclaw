<?php

namespace App\Libraries\UI;

/**
 * Pretty terminal UI for PHPClaw.
 *
 * Provides box drawing, tables, interactive menus, styled prompts,
 * spinners, progress bars, and proper readline input handling.
 *
 * Uses Unicode box-drawing characters and ANSI escape codes.
 * Falls back to ASCII if the terminal doesn't support Unicode.
 */
class TerminalUI
{
    // ANSI color codes
    private const COLORS = [
        'reset'        => "\033[0m",
        'bold'         => "\033[1m",
        'dim'          => "\033[2m",
        'italic'       => "\033[3m",
        'underline'    => "\033[4m",
        'blink'        => "\033[5m",
        'reverse'      => "\033[7m",
        'hidden'       => "\033[8m",
        'black'        => "\033[30m",
        'red'          => "\033[31m",
        'green'        => "\033[32m",
        'yellow'       => "\033[33m",
        'blue'         => "\033[34m",
        'magenta'      => "\033[35m",
        'cyan'         => "\033[36m",
        'white'        => "\033[37m",
        'gray'         => "\033[90m",
        'bright_red'   => "\033[91m",
        'bright_green' => "\033[92m",
        'bright_yellow'=> "\033[93m",
        'bright_blue'  => "\033[94m",
        'bright_magenta' => "\033[95m",
        'bright_cyan'  => "\033[96m",
        'bright_white' => "\033[97m",
        'bg_black'     => "\033[40m",
        'bg_red'       => "\033[41m",
        'bg_green'     => "\033[42m",
        'bg_yellow'    => "\033[43m",
        'bg_blue'      => "\033[44m",
        'bg_magenta'   => "\033[45m",
        'bg_cyan'      => "\033[46m",
        'bg_white'     => "\033[47m",
    ];

    // Box-drawing characters (Unicode)
    private const BOX = [
        'tl' => '╭', 'tr' => '╮', 'bl' => '╰', 'br' => '╯',
        'h'  => '─', 'v'  => '│',
        'lt' => '├', 'rt' => '┤', 'tt' => '┬', 'bt' => '┴',
        'cross' => '┼',
    ];

    // Heavy box for emphasis
    private const BOX_HEAVY = [
        'tl' => '┏', 'tr' => '┓', 'bl' => '┗', 'br' => '┛',
        'h'  => '━', 'v'  => '┃',
        'lt' => '┣', 'rt' => '┫', 'tt' => '┳', 'bt' => '┻',
        'cross' => '╋',
    ];

    // Double box for headers
    private const BOX_DOUBLE = [
        'tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝',
        'h'  => '═', 'v'  => '║',
        'lt' => '╠', 'rt' => '╣', 'tt' => '╦', 'bt' => '╩',
        'cross' => '╬',
    ];

    private int $width;
    private bool $unicode;

    public function __construct()
    {
        $this->width = $this->detectWidth();
        $this->unicode = $this->detectUnicode();
    }

    // ── Output helpers ──────────────────────────────────────────────

    public function write(string $text, string ...$styles): void
    {
        echo $this->style($text, ...$styles) . "\n";
    }

    public function writeln(string $text = '', string ...$styles): void
    {
        $this->write($text, ...$styles);
    }

    public function inline(string $text, string ...$styles): void
    {
        echo $this->style($text, ...$styles);
    }

    public function newLine(int $count = 1): void
    {
        echo str_repeat("\n", $count);
    }

    public function style(string $text, string ...$styles): string
    {
        if (empty($styles)) return $text;
        $prefix = '';
        foreach ($styles as $s) {
            if (isset(self::COLORS[$s])) {
                $prefix .= self::COLORS[$s];
            }
        }
        return $prefix . $text . self::COLORS['reset'];
    }

    public function clearLine(): void
    {
        echo "\033[2K\r";
    }

    public function cursorUp(int $lines = 1): void
    {
        echo "\033[{$lines}A";
    }

    public function cursorDown(int $lines = 1): void
    {
        echo "\033[{$lines}B";
    }

    public function hideCursor(): void
    {
        echo "\033[?25l";
    }

    public function showCursor(): void
    {
        echo "\033[?25h";
    }

    // ── Banners & Headers ───────────────────────────────────────────

    /**
     * Display a large banner with double-line box.
     */
    public function banner(string $title, string $subtitle = '', string $color = 'cyan'): void
    {
        $box = self::BOX_DOUBLE;
        $w = max($this->width - 4, 40);
        $inner = $w - 2;

        $this->newLine();
        $this->write("  {$box['tl']}" . str_repeat($box['h'], $inner) . "{$box['tr']}", $color);

        // Empty line
        $this->write("  {$box['v']}" . str_repeat(' ', $inner) . "{$box['v']}", $color);

        // Title centered
        $titlePad = $this->centerPad($title, $inner);
        $this->inline("  {$box['v']}", $color);
        $this->inline($titlePad, $color, 'bold');
        $this->write($box['v'], $color);

        if ($subtitle) {
            $subPad = $this->centerPad($subtitle, $inner);
            $this->inline("  {$box['v']}", $color);
            $this->inline($subPad, 'gray');
            $this->write($box['v'], $color);
        }

        // Empty line
        $this->write("  {$box['v']}" . str_repeat(' ', $inner) . "{$box['v']}", $color);
        $this->write("  {$box['bl']}" . str_repeat($box['h'], $inner) . "{$box['br']}", $color);
        $this->newLine();
    }

    /**
     * Section header with a line underneath.
     */
    public function header(string $text, string $color = 'bright_cyan'): void
    {
        $this->newLine();
        $this->write("  {$text}", $color, 'bold');
        $len = mb_strlen($text) + 2;
        $this->write("  " . str_repeat('─', min($len, $this->width - 4)), $color);
    }

    /**
     * Step header for wizard flows.
     * Example: ━━ Step 1 of 7 ━━ Environment Check
     */
    public function stepHeader(int $current, int $total, string $label): void
    {
        $this->newLine();
        $stepText = "Step {$current} of {$total}";
        $line = "  ━━ ";
        $this->inline($line, 'bright_blue');
        $this->inline($stepText, 'bright_blue', 'bold');
        $this->inline(" ━━ ", 'bright_blue');
        $this->write($label, 'white', 'bold');
        $this->newLine();
    }

    // ── Boxes ───────────────────────────────────────────────────────

    /**
     * Draw a box around content lines.
     */
    public function box(array $lines, string $color = 'cyan', string $style = 'normal'): void
    {
        $chars = match ($style) {
            'heavy'  => self::BOX_HEAVY,
            'double' => self::BOX_DOUBLE,
            default  => self::BOX,
        };

        $maxLen = 0;
        foreach ($lines as $line) {
            $maxLen = max($maxLen, mb_strlen($this->stripAnsi($line)));
        }
        $maxLen = max($maxLen + 4, 20);

        $this->write("  {$chars['tl']}" . str_repeat($chars['h'], $maxLen) . "{$chars['tr']}", $color);
        foreach ($lines as $line) {
            $stripped = $this->stripAnsi($line);
            $pad = $maxLen - mb_strlen($stripped);
            $this->inline("  {$chars['v']} ", $color);
            echo $line;
            $this->write(str_repeat(' ', max(0, $pad - 1)) . $chars['v'], $color);
        }
        $this->write("  {$chars['bl']}" . str_repeat($chars['h'], $maxLen) . "{$chars['br']}", $color);
    }

    /**
     * Info box (blue border).
     */
    public function infoBox(string ...$lines): void
    {
        $this->box(array_map(fn($l) => $this->style($l, 'white'), $lines), 'blue');
    }

    /**
     * Success box (green border).
     */
    public function successBox(string ...$lines): void
    {
        $this->box(array_map(fn($l) => $this->style($l, 'bright_green'), $lines), 'green');
    }

    /**
     * Warning box (yellow border).
     */
    public function warnBox(string ...$lines): void
    {
        $this->box(array_map(fn($l) => $this->style($l, 'bright_yellow'), $lines), 'yellow');
    }

    /**
     * Error box (red border).
     */
    public function errorBox(string ...$lines): void
    {
        $this->box(array_map(fn($l) => $this->style($l, 'bright_red'), $lines), 'red');
    }

    // ── Tables ──────────────────────────────────────────────────────

    /**
     * Render a table with headers and rows.
     *
     * @param string[] $headers
     * @param array<array<string>> $rows
     */
    public function table(array $headers, array $rows, string $color = 'cyan'): void
    {
        $box = self::BOX;

        // Calculate column widths
        $colWidths = [];
        foreach ($headers as $i => $h) {
            $colWidths[$i] = mb_strlen($h);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $colWidths[$i] = max($colWidths[$i] ?? 0, mb_strlen($this->stripAnsi((string)$cell)));
            }
        }

        // Add padding
        foreach ($colWidths as &$w) {
            $w += 2;
        }

        // Top border
        $topParts = [];
        foreach ($colWidths as $w) {
            $topParts[] = str_repeat($box['h'], $w);
        }
        $this->write("  {$box['tl']}" . implode($box['tt'], $topParts) . "{$box['tr']}", $color);

        // Header row
        $headerCells = [];
        foreach ($headers as $i => $h) {
            $pad = $colWidths[$i] - mb_strlen($h) - 1;
            $headerCells[] = ' ' . $this->style($h, 'bold', 'white') . str_repeat(' ', max(0, $pad));
        }
        $this->write("  {$box['v']}" . implode($this->style($box['v'], $color), $headerCells) . "{$box['v']}", $color);

        // Separator
        $sepParts = [];
        foreach ($colWidths as $w) {
            $sepParts[] = str_repeat($box['h'], $w);
        }
        $this->write("  {$box['lt']}" . implode($box['cross'], $sepParts) . "{$box['rt']}", $color);

        // Data rows
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $stripped = $this->stripAnsi((string)$cell);
                $pad = ($colWidths[$i] ?? 10) - mb_strlen($stripped) - 1;
                $cells[] = ' ' . $cell . str_repeat(' ', max(0, $pad));
            }
            $this->write("  {$box['v']}" . implode($this->style($box['v'], $color), $cells) . "{$box['v']}", $color);
        }

        // Bottom border
        $bottomParts = [];
        foreach ($colWidths as $w) {
            $bottomParts[] = str_repeat($box['h'], $w);
        }
        $this->write("  {$box['bl']}" . implode($box['bt'], $bottomParts) . "{$box['br']}", $color);
    }

    /**
     * Simple key-value display.
     */
    public function keyValue(array $pairs, string $keyColor = 'cyan', int $keyWidth = 0): void
    {
        if ($keyWidth === 0) {
            foreach ($pairs as $k => $v) {
                $keyWidth = max($keyWidth, mb_strlen($k));
            }
        }

        foreach ($pairs as $key => $value) {
            $pad = $keyWidth - mb_strlen($key);
            $this->inline("  " . $this->style($key, $keyColor));
            $this->inline(str_repeat(' ', max(0, $pad)) . "  ");
            $this->write((string)$value);
        }
    }

    // ── Status indicators ───────────────────────────────────────────

    /**
     * Check/cross status line.
     */
    public function check(string $label, bool $ok, string $detail = ''): void
    {
        $icon = $ok ? $this->style('  ✓', 'bright_green') : $this->style('  ✗', 'bright_red');
        $text = $ok ? $this->style($label, 'white') : $this->style($label, 'red');
        $suffix = $detail ? $this->style(" ({$detail})", 'gray') : '';
        echo "{$icon} {$text}{$suffix}\n";
    }

    /**
     * Bullet point.
     */
    public function bullet(string $text, string $color = 'white'): void
    {
        $this->write("  • {$text}", $color);
    }

    /**
     * Dimmed info line.
     */
    public function dim(string $text): void
    {
        $this->write("  {$text}", 'gray');
    }

    /**
     * Success message.
     */
    public function success(string $text): void
    {
        $this->write("  ✓ {$text}", 'bright_green');
    }

    /**
     * Warning message.
     */
    public function warn(string $text): void
    {
        $this->write("  ⚠ {$text}", 'bright_yellow');
    }

    /**
     * Error message.
     */
    public function error(string $text): void
    {
        $this->write("  ✗ {$text}", 'bright_red');
    }

    /**
     * Info message.
     */
    public function info(string $text): void
    {
        $this->write("  ℹ {$text}", 'bright_blue');
    }

    // ── Spinners & Progress ─────────────────────────────────────────

    private const SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * Show a spinner while a callback executes.
     * Returns the callback's return value.
     */
    public function spinner(string $message, callable $callback): mixed
    {
        // If not a TTY, just run it
        if (!$this->isTty()) {
            $this->write("  {$message}...", 'gray');
            return $callback();
        }

        $this->hideCursor();
        $result = null;
        $done = false;
        $frame = 0;

        // We can't do true async in PHP easily, so we just show start/end
        $this->inline("  " . self::SPINNER_FRAMES[0] . " {$message}", 'cyan');

        try {
            $result = $callback();
            $this->clearLine();
            echo "  " . $this->style("✓ {$message}", 'bright_green') . "\n";
        } catch (\Throwable $e) {
            $this->clearLine();
            echo "  " . $this->style("✗ {$message}: " . $e->getMessage(), 'bright_red') . "\n";
            $this->showCursor();
            throw $e;
        }

        $this->showCursor();
        return $result;
    }

    /**
     * Progress bar.
     */
    public function progressBar(int $current, int $total, int $width = 30, string $label = ''): void
    {
        $ratio = $total > 0 ? $current / $total : 0;
        $filled = (int)round($ratio * $width);
        $empty = $width - $filled;

        $bar = $this->style(str_repeat('█', $filled), 'bright_green')
             . $this->style(str_repeat('░', $empty), 'gray');

        $pct = (int)round($ratio * 100);
        $info = "{$current}/{$total}";
        $prefix = $label ? "{$label} " : '';

        $this->clearLine();
        $this->inline("  {$prefix}{$bar} {$pct}% {$info}");

        if ($current >= $total) {
            echo "\n";
        }
    }

    // ── Usage / Token Display ───────────────────────────────────────

    /**
     * Display a turn usage summary line (shown after each agent response).
     * Compact single-line format like Claude Code:
     *   ─ 847 in · 234 out · $0.004 · 2 tools · 1.2s ─
     */
    public function turnUsage(string $summary): void
    {
        if (empty($summary)) return;
        $line = $this->style("  ─ {$summary} ─", 'gray');
        echo "{$line}\n";
    }

    /**
     * Display a full session usage panel (for /usage command).
     */
    public function usagePanel(array $session, array $perModel = []): void
    {
        $this->header('Session Usage');
        $this->newLine();

        $this->keyValue([
            'Input tokens'  => $this->style((string)($session['input_tokens'] ?? 0), 'bright_cyan'),
            'Output tokens' => $this->style((string)($session['output_tokens'] ?? 0), 'bright_green'),
            'Total tokens'  => $this->style((string)($session['total_tokens'] ?? 0), 'bright_white', 'bold'),
            'Est. cost'     => $this->style($session['cost_formatted'] ?? '$0.00', 'bright_yellow'),
            'API requests'  => (string)($session['requests'] ?? 0),
            'Tool calls'    => (string)($session['tool_calls'] ?? 0),
            'Turns'         => (string)($session['turns'] ?? 0),
            'Duration'      => $session['elapsed_formatted'] ?? '0s',
        ]);

        if (!empty($perModel)) {
            $this->newLine();
            $this->divider('Per Model', 'bright_cyan');
            $this->newLine();

            $rows = [];
            foreach ($perModel as $model => $data) {
                $rows[] = [
                    $this->style($model, 'bright_cyan'),
                    (string)($data['input'] ?? 0),
                    (string)($data['output'] ?? 0),
                    $data['cost_formatted'] ?? '$0.00',
                    (string)($data['requests'] ?? 0),
                ];
            }
            $this->table(['Model', 'Input', 'Output', 'Cost', 'Requests'], $rows, 'blue');
        }
        $this->newLine();
    }

    /**
     * Display a compact session cost in the banner area.
     * Example:  Session cost: $0.04 (12.4k tokens)
     */
    public function sessionCostLine(string $cost, string $tokens): void
    {
        $this->inline("  Session: ");
        $this->inline($this->style($cost, 'bright_yellow'));
        $this->write($this->style(" ({$tokens} tokens)", 'gray'));
    }

    // ── Interactive Input ───────────────────────────────────────────

    /**
     * Styled prompt with readline support.
     * Returns the user's input string, or null on EOF/cancel.
     */
    public function prompt(string $label, string $default = '', bool $secret = false): ?string
    {
        $defaultHint = $default !== '' ? $this->style(" [{$default}]", 'gray') : '';
        $arrow = $this->style('  ❯ ', 'bright_cyan');

        echo "{$arrow}{$label}{$defaultHint}: ";

        if ($secret) {
            // Hide input for passwords/keys
            system('stty -echo 2>/dev/null');
            $input = $this->readLine();
            system('stty echo 2>/dev/null');
            echo "\n";
        } else {
            $input = $this->readLine();
        }

        if ($input === null) return null;
        $input = trim($input);
        return $input === '' ? $default : $input;
    }

    /**
     * Yes/no confirmation prompt.
     */
    public function confirm(string $question, bool $default = true): bool
    {
        $hint = $default ? 'Y/n' : 'y/N';
        $arrow = $this->style('  ❯ ', 'bright_cyan');

        echo "{$arrow}{$question} " . $this->style("[{$hint}]", 'gray') . ": ";
        $input = strtolower(trim($this->readLine() ?? ''));

        if ($input === '') return $default;
        return in_array($input, ['y', 'yes', '1', 'true'], true);
    }

    /**
     * Interactive numbered menu with arrow-key navigation.
     * Falls back to numbered input if raw mode isn't available.
     *
     * @param string $title Menu title
     * @param array<array{label: string, description?: string, value?: string}> $options
     * @param int $default Default selected index (0-based)
     * @return int|null Selected index, or null on cancel
     */
    public function menu(string $title, array $options, int $default = 0): ?int
    {
        if (empty($options)) return null;

        // Try interactive mode first
        if ($this->isTty() && $this->canRawMode()) {
            return $this->interactiveMenu($title, $options, $default);
        }

        // Fallback: numbered list
        return $this->numberedMenu($title, $options, $default);
    }

    /**
     * Full arrow-key interactive menu.
     */
    private function interactiveMenu(string $title, array $options, int $selected): ?int
    {
        $this->newLine();
        $this->write("  {$title}", 'bright_cyan', 'bold');
        $this->dim("  Use ↑/↓ arrows to navigate, Enter to select, q to cancel");
        $this->newLine();

        $count = count($options);
        $this->hideCursor();

        // Initial render
        $this->renderMenuOptions($options, $selected);

        // Enter raw mode
        $oldStty = trim(shell_exec('stty -g 2>/dev/null') ?? '');
        system('stty -icanon -echo 2>/dev/null');

        try {
            while (true) {
                $char = $this->readChar();

                if ($char === null || $char === 'q' || $char === "\033" && !$this->hasMoreInput()) {
                    // Cancel
                    $this->cursorUp($count);
                    for ($i = 0; $i < $count; $i++) {
                        $this->clearLine();
                        $this->cursorDown();
                    }
                    $this->cursorUp($count);
                    $this->showCursor();
                    if ($oldStty) system("stty {$oldStty} 2>/dev/null");
                    return null;
                }

                if ($char === "\n" || $char === "\r") {
                    // Confirm selection
                    $this->cursorUp($count);
                    for ($i = 0; $i < $count; $i++) {
                        $this->clearLine();
                        $this->cursorDown();
                    }
                    $this->cursorUp($count);
                    $label = $options[$selected]['label'] ?? "Option " . ($selected + 1);
                    $this->write("  ✓ {$label}", 'bright_green');
                    $this->showCursor();
                    if ($oldStty) system("stty {$oldStty} 2>/dev/null");
                    return $selected;
                }

                if ($char === "\033") {
                    // Read escape sequence
                    $seq1 = $this->readChar();
                    $seq2 = $this->readChar();
                    if ($seq1 === '[') {
                        if ($seq2 === 'A') {
                            // Up arrow
                            $selected = ($selected - 1 + $count) % $count;
                        } elseif ($seq2 === 'B') {
                            // Down arrow
                            $selected = ($selected + 1) % $count;
                        }
                    }
                }

                // Number keys for quick select
                if (is_numeric($char) && (int)$char >= 1 && (int)$char <= $count) {
                    $selected = (int)$char - 1;
                }

                // Re-render
                $this->cursorUp($count);
                $this->renderMenuOptions($options, $selected);
            }
        } catch (\Throwable $e) {
            $this->showCursor();
            if ($oldStty) system("stty {$oldStty} 2>/dev/null");
            throw $e;
        }
    }

    private function renderMenuOptions(array $options, int $selected): void
    {
        foreach ($options as $i => $opt) {
            $this->clearLine();
            $label = $opt['label'] ?? "Option " . ($i + 1);
            $desc = $opt['description'] ?? '';
            $num = $i + 1;

            if ($i === $selected) {
                $this->inline($this->style("  ❯ ", 'bright_cyan'));
                $this->inline($this->style("{$num}) {$label}", 'bright_white', 'bold'));
                if ($desc) {
                    $this->inline($this->style(" — {$desc}", 'gray'));
                }
            } else {
                $this->inline($this->style("    {$num}) {$label}", 'white'));
                if ($desc) {
                    $this->inline($this->style(" — {$desc}", 'gray'));
                }
            }
            echo "\n";
        }
    }

    /**
     * Fallback numbered menu for non-interactive terminals.
     */
    private function numberedMenu(string $title, array $options, int $default): ?int
    {
        $this->newLine();
        $this->write("  {$title}", 'bright_cyan', 'bold');
        $this->newLine();

        foreach ($options as $i => $opt) {
            $num = $i + 1;
            $label = $opt['label'] ?? "Option {$num}";
            $desc = isset($opt['description']) ? $this->style(" — {$opt['description']}", 'gray') : '';
            $marker = ($i === $default) ? $this->style(' (default)', 'gray') : '';
            $this->write("    {$num}) {$label}{$desc}{$marker}");
        }
        $this->newLine();

        $input = $this->prompt('Select', (string)($default + 1));
        if ($input === null) return null;

        $choice = (int)$input - 1;
        if ($choice < 0 || $choice >= count($options)) {
            return $default;
        }
        return $choice;
    }

    /**
     * Multi-step wizard with back navigation.
     *
     * Each step is a callable that receives ($ui, $data) and returns:
     *   - 'next'     to proceed to the next step
     *   - 'back'     to go to the previous step
     *   - 'skip'     to skip this step
     *   - 'abort'    to cancel the wizard
     *   - any other value is stored as the step's result and proceeds to next
     *
     * @param array<array{label: string, callback: callable}> $steps
     * @param array $data Shared data bag passed to all steps
     * @return array The data bag after all steps complete
     */
    public function wizard(array $steps, array $data = []): array
    {
        $total = count($steps);
        $current = 0;

        while ($current < $total) {
            $step = $steps[$current];
            $label = $step['label'] ?? "Step " . ($current + 1);
            $callback = $step['callback'];

            $this->stepHeader($current + 1, $total, $label);

            if ($current > 0) {
                $this->dim("Type 'back' at any prompt to go to the previous step");
            }

            $result = $callback($this, $data);

            if ($result === 'back' && $current > 0) {
                $current--;
                continue;
            }
            if ($result === 'abort') {
                $data['_aborted'] = true;
                return $data;
            }
            if ($result === 'skip') {
                $current++;
                continue;
            }

            // Store result if it's meaningful
            if ($result !== 'next' && $result !== null) {
                $data[$step['key'] ?? "step_{$current}"] = $result;
            }

            $current++;
        }

        return $data;
    }

    // ── Chat-specific rendering ─────────────────────────────────────

    /**
     * Chat prompt with module:provider display.
     * Uses readline for proper line editing with history support.
     */
    public function chatPrompt(string $module, string $provider): ?string
    {
        $prefix = $this->style($module, 'bright_magenta') . $this->style(':', 'gray') . $this->style($provider, 'bright_blue');
        $prompt = "\n  {$prefix} " . $this->style('❯ ', 'bright_cyan');

        // Use readline if available for proper editing
        if (function_exists('readline')) {
            $input = readline($prompt);
            if ($input === false) return null;
            $input = trim($input);
            if ($input !== '') {
                readline_add_history($input);
            }
            return $input;
        }

        // Fallback
        echo $prompt;
        return $this->readLine();
    }

    /**
     * Display an agent response in a styled block.
     */
    public function agentResponse(string $text): void
    {
        $this->newLine();
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $this->write("  {$line}", 'white');
        }
        $this->newLine();
    }

    /**
     * Display a tool call status.
     */
    public function toolCall(string $name, bool $success, string $detail = ''): void
    {
        $icon = $success ? $this->style('✓', 'bright_green') : $this->style('✗', 'bright_red');
        $nameStyled = $this->style($name, 'bright_cyan');
        $suffix = $detail ? $this->style(" {$detail}", 'gray') : '';
        echo "    {$icon} {$nameStyled}{$suffix}\n";
    }

    /**
     * Display a thinking/working indicator.
     */
    public function thinking(string $message = 'Thinking'): void
    {
        $dots = $this->style('...', 'gray');
        $this->inline("  " . $this->style('◆', 'bright_magenta') . " {$message}{$dots}", 'gray');
    }

    /**
     * Complete a thinking indicator.
     */
    public function thinkingDone(): void
    {
        $this->clearLine();
    }

    // ── Slash command help rendering ────────────────────────────────

    /**
     * Render slash command help in a nice table format.
     */
    public function slashHelp(array $commands): void
    {
        $this->header('Commands');
        $maxLen = 0;
        foreach ($commands as $cmd => $desc) {
            $maxLen = max($maxLen, mb_strlen($cmd));
        }
        foreach ($commands as $cmd => $desc) {
            $pad = $maxLen - mb_strlen($cmd);
            $this->inline("    " . $this->style($cmd, 'bright_yellow'));
            $this->write(str_repeat(' ', $pad + 2) . $desc, 'gray');
        }
    }

    // ── Dividers ────────────────────────────────────────────────────

    /**
     * Horizontal rule.
     */
    public function hr(string $color = 'gray'): void
    {
        $this->write("  " . str_repeat('─', min($this->width - 4, 60)), $color);
    }

    /**
     * Labeled divider: ── Label ──────────
     */
    public function divider(string $label = '', string $color = 'gray'): void
    {
        if ($label === '') {
            $this->hr($color);
            return;
        }
        $lineLen = min($this->width - 4, 60);
        $labelLen = mb_strlen($label) + 2;
        $remaining = max(0, $lineLen - $labelLen - 3);
        $this->write("  ── {$label} " . str_repeat('─', $remaining), $color);
    }

    // ── Internal helpers ────────────────────────────────────────────

    /**
     * Read a line from stdin with proper handling.
     */
    private function readLine(): ?string
    {
        $line = fgets(STDIN);
        if ($line === false) return null;
        return rtrim($line, "\n\r");
    }

    /**
     * Read a single character from stdin (raw mode).
     */
    private function readChar(): ?string
    {
        $char = fread(STDIN, 1);
        return $char === false ? null : $char;
    }

    /**
     * Check if there's more input available (for escape sequences).
     */
    private function hasMoreInput(): bool
    {
        $read = [STDIN];
        $write = null;
        $except = null;
        return stream_select($read, $write, $except, 0, 50000) > 0;
    }

    /**
     * Strip ANSI escape codes from a string.
     */
    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    /**
     * Center-pad a string within a given width.
     */
    private function centerPad(string $text, int $width): string
    {
        $textLen = mb_strlen($text);
        if ($textLen >= $width) return $text;
        $left = (int)(($width - $textLen) / 2);
        $right = $width - $textLen - $left;
        return str_repeat(' ', $left) . $text . str_repeat(' ', $right);
    }

    /**
     * Detect terminal width.
     */
    private function detectWidth(): int
    {
        // Try stty
        $cols = trim(shell_exec('tput cols 2>/dev/null') ?? '');
        if ($cols && is_numeric($cols)) {
            return (int)$cols;
        }

        // Try stty size
        $size = trim(shell_exec('stty size 2>/dev/null') ?? '');
        if ($size && preg_match('/\d+ (\d+)/', $size, $m)) {
            return (int)$m[1];
        }

        // Environment variable
        $env = getenv('COLUMNS');
        if ($env && is_numeric($env)) {
            return (int)$env;
        }

        return 80; // Default
    }

    /**
     * Detect if terminal supports Unicode.
     */
    private function detectUnicode(): bool
    {
        $lang = getenv('LANG') ?: getenv('LC_ALL') ?: '';
        return stripos($lang, 'utf') !== false || stripos($lang, 'UTF') !== false || PHP_OS_FAMILY !== 'Windows';
    }

    /**
     * Check if we're running in a TTY.
     */
    public function isTty(): bool
    {
        return function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
    }

    /**
     * Check if we can enter raw terminal mode.
     */
    private function canRawMode(): bool
    {
        return !empty(trim(shell_exec('which stty 2>/dev/null') ?? ''));
    }

    public function getWidth(): int
    {
        return $this->width;
    }
}
