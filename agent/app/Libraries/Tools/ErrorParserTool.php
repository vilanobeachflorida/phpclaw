<?php

namespace App\Libraries\Tools;

/**
 * Universal error normalization — extracts structured error information
 * from raw CLI output, log files, or exception traces across any language.
 *
 * Actions:
 *   parse      – parse raw error output into structured errors
 *   parse_file – read and parse a log file for errors
 */
class ErrorParserTool extends BaseTool
{
    protected string $name = 'error_parser';
    protected string $description = 'Parse raw error output from any language into structured errors with file, line, and message';

    protected function getDefaultConfig(): array
    {
        return ['enabled' => true, 'timeout' => 10, 'max_errors' => 100];
    }

    public function getInputSchema(): array
    {
        return [
            'action'   => ['type' => 'string', 'required' => true, 'enum' => ['parse', 'parse_file']],
            'input'    => ['type' => 'string', 'required' => false, 'description' => 'Raw error output to parse (action=parse)'],
            'path'     => ['type' => 'string', 'required' => false, 'description' => 'Log file to parse (action=parse_file)'],
            'language' => ['type' => 'string', 'required' => false, 'description' => 'Hint language for better parsing (php, python, node, go, rust, java, ruby, etc.)'],
            'tail'     => ['type' => 'int',    'required' => false, 'default' => 200, 'description' => 'Lines from end of file to parse (action=parse_file)'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        switch ($args['action']) {
            case 'parse':
                if ($err = $this->requireArgs($args, ['input'])) return $err;
                return $this->parse($args['input'], $args['language'] ?? null);

            case 'parse_file':
                if ($err = $this->requireArgs($args, ['path'])) return $err;
                $path = $args['path'];
                if (!file_exists($path)) return $this->error("File not found: {$path}");
                $tail = $args['tail'] ?? 200;
                $lines = file($path, FILE_IGNORE_NEW_LINES);
                $content = implode("\n", array_slice($lines, -$tail));
                return $this->parse($content, $args['language'] ?? null);

            default:
                return $this->error("Unknown action: {$args['action']}");
        }
    }

    private function parse(string $input, ?string $language): array
    {
        $errors = [];
        $stack  = [];
        $maxErrors = $this->config['max_errors'];

        // Auto-detect language if not specified
        if (!$language) {
            $language = $this->detectLanguage($input);
        }

        // Run language-specific parsers
        switch ($language) {
            case 'php':
                $errors = array_merge($errors, $this->parsePHP($input));
                break;
            case 'python':
                $errors = array_merge($errors, $this->parsePython($input));
                break;
            case 'node':
            case 'javascript':
            case 'typescript':
                $errors = array_merge($errors, $this->parseNode($input));
                break;
            case 'go':
                $errors = array_merge($errors, $this->parseGo($input));
                break;
            case 'rust':
                $errors = array_merge($errors, $this->parseRust($input));
                break;
            case 'java':
            case 'kotlin':
                $errors = array_merge($errors, $this->parseJava($input));
                break;
            case 'ruby':
                $errors = array_merge($errors, $this->parseRuby($input));
                break;
            case 'c':
            case 'cpp':
                $errors = array_merge($errors, $this->parseCCpp($input));
                break;
        }

        // Always run generic parsers as fallback
        $errors = array_merge($errors, $this->parseGeneric($input));

        // Parse stack traces
        $stack = $this->parseStackTrace($input, $language);

        // Deduplicate by file+line+message
        $seen = [];
        $unique = [];
        foreach ($errors as $e) {
            $key = ($e['file'] ?? '') . ':' . ($e['line'] ?? '') . ':' . ($e['message'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $e;
            if (count($unique) >= $maxErrors) break;
        }

        return $this->success([
            'language'    => $language,
            'error_count' => count($unique),
            'errors'      => $unique,
            'stack_trace' => $stack,
            'has_fatal'   => $this->hasFatal($input),
        ]);
    }

    // ── language detection ────────────────────────────────────

    private function detectLanguage(string $input): ?string
    {
        if (preg_match('/PHP (Fatal|Parse|Warning|Notice|Deprecated)/', $input)) return 'php';
        if (preg_match('/Traceback \(most recent call last\)/', $input)) return 'python';
        if (preg_match('/at Object\.<anonymous>|TypeError:|ReferenceError:|SyntaxError:.+\.js/', $input)) return 'node';
        if (preg_match('/error\[E\d{4}\]:/', $input)) return 'rust';
        if (preg_match('/\.go:\d+:\d+:/', $input)) return 'go';
        if (preg_match('/at\s+[\w.]+\([\w.]+\.java:\d+\)/', $input)) return 'java';
        if (preg_match('/\.rb:\d+:in\s+`/', $input)) return 'ruby';
        if (preg_match('/error:|warning:.*\.c(?:pp)?:\d+/', $input)) return 'cpp';
        return null;
    }

    // ── PHP ───────────────────────────────────────────────────

    private function parsePHP(string $input): array
    {
        $errors = [];

        // Fatal error: message in /path/file.php on line 42
        if (preg_match_all('/PHP\s+(Fatal error|Parse error|Warning|Notice|Deprecated):\s*(.+?)\s+in\s+(.+?)\s+on line\s+(\d+)/i', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'     => strtolower(str_replace(' ', '_', $m[1])),
                    'message'  => trim($m[2]),
                    'file'     => $m[3],
                    'line'     => (int)$m[4],
                    'severity' => $this->phpSeverity($m[1]),
                ];
            }
        }

        // Uncaught Exception: message in /path:42
        if (preg_match_all('/Uncaught\s+(\w+(?:\\\\\\w+)*):\s*(.+?)\s+in\s+(.+?):(\d+)/', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'     => 'uncaught_exception',
                    'message'  => "{$m[1]}: {$m[2]}",
                    'file'     => $m[3],
                    'line'     => (int)$m[4],
                    'severity' => 'error',
                ];
            }
        }

        return $errors;
    }

    private function phpSeverity(string $type): string
    {
        $t = strtolower($type);
        if (str_contains($t, 'fatal') || str_contains($t, 'parse')) return 'error';
        if (str_contains($t, 'warning')) return 'warning';
        return 'info';
    }

    // ── Python ────────────────────────────────────────────────

    private function parsePython(string $input): array
    {
        $errors = [];

        // SyntaxError / IndentationError
        if (preg_match_all('/File "(.+?)", line (\d+).*\n\s*.*\n\s*(\w+Error):\s*(.+)/m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'     => $m[3],
                    'message'  => trim($m[4]),
                    'file'     => $m[1],
                    'line'     => (int)$m[2],
                    'severity' => 'error',
                ];
            }
        }

        // Simple: ErrorType: message
        if (preg_match_all('/^(\w+Error):\s*(.+)$/m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => $m[1],
                    'message' => trim($m[2]),
                    'file'    => null,
                    'line'    => null,
                    'severity'=> 'error',
                ];
            }
        }

        return $errors;
    }

    // ── Node.js ───────────────────────────────────────────────

    private function parseNode(string $input): array
    {
        $errors = [];

        // /path/file.js:42
        //   throw new Error(...)
        // Error: message
        if (preg_match_all('#^(.+?\.(?:js|ts|mjs|cjs|tsx|jsx)):(\d+)(?::(\d+))?#m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => 'error',
                    'file'    => $m[1],
                    'line'    => (int)$m[2],
                    'column'  => isset($m[3]) ? (int)$m[3] : null,
                    'message' => $this->extractNearbyMessage($input, $m[0]),
                    'severity'=> 'error',
                ];
            }
        }

        // TypeError: X is not a function
        if (preg_match_all('/^(TypeError|ReferenceError|SyntaxError|RangeError|URIError):\s*(.+)$/m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => $m[1],
                    'message' => trim($m[2]),
                    'file'    => null,
                    'line'    => null,
                    'severity'=> 'error',
                ];
            }
        }

        return $errors;
    }

    // ── Go ────────────────────────────────────────────────────

    private function parseGo(string $input): array
    {
        $errors = [];

        // ./file.go:42:10: message
        if (preg_match_all('#^(.+?\.go):(\d+):(\d+):\s*(.+)$#m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => 'compile_error',
                    'file'    => $m[1],
                    'line'    => (int)$m[2],
                    'column'  => (int)$m[3],
                    'message' => trim($m[4]),
                    'severity'=> 'error',
                ];
            }
        }

        // panic: message
        if (preg_match('/^panic:\s*(.+)$/m', $input, $m)) {
            $errors[] = [
                'type'    => 'panic',
                'message' => trim($m[1]),
                'file'    => null,
                'line'    => null,
                'severity'=> 'error',
            ];
        }

        return $errors;
    }

    // ── Rust ──────────────────────────────────────────────────

    private function parseRust(string $input): array
    {
        $errors = [];

        // error[E0308]: mismatched types
        //  --> src/file.rs:42:10
        if (preg_match_all('/^(error|warning)(?:\[E(\d+)\])?:\s*(.+?)$\s*-->\s*(.+?):(\d+):(\d+)/m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => $m[2] ? "E{$m[2]}" : $m[1],
                    'message' => trim($m[3]),
                    'file'    => $m[4],
                    'line'    => (int)$m[5],
                    'column'  => (int)$m[6],
                    'severity'=> $m[1] === 'error' ? 'error' : 'warning',
                ];
            }
        }

        return $errors;
    }

    // ── Java / Kotlin ─────────────────────────────────────────

    private function parseJava(string $input): array
    {
        $errors = [];

        // file.java:42: error: message
        if (preg_match_all('/^(.+?\.(?:java|kt)):(\d+):\s*(error|warning):\s*(.+)$/m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => $m[3],
                    'file'    => $m[1],
                    'line'    => (int)$m[2],
                    'message' => trim($m[4]),
                    'severity'=> $m[3],
                ];
            }
        }

        // Exception in thread "main" java.lang.NullPointerException: message
        if (preg_match('/Exception in thread .+?\s+([\w.]+(?:Exception|Error)):\s*(.+)/m', $input, $m)) {
            $errors[] = [
                'type'    => $m[1],
                'message' => trim($m[2]),
                'file'    => null,
                'line'    => null,
                'severity'=> 'error',
            ];
        }

        return $errors;
    }

    // ── Ruby ──────────────────────────────────────────────────

    private function parseRuby(string $input): array
    {
        $errors = [];

        // file.rb:42:in `method': message (ErrorType)
        if (preg_match_all("#^(.+?\.rb):(\d+):in\s+`(.+?)':\s*(.+?)\s*\((\w+)\)#m", $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => $m[5],
                    'file'    => $m[1],
                    'line'    => (int)$m[2],
                    'message' => trim($m[4]),
                    'method'  => $m[3],
                    'severity'=> 'error',
                ];
            }
        }

        return $errors;
    }

    // ── C/C++ ─────────────────────────────────────────────────

    private function parseCCpp(string $input): array
    {
        $errors = [];

        // file.c:42:10: error: message
        if (preg_match_all('#^(.+?\.(?:c|cpp|cc|cxx|h|hpp)):(\d+):(\d+):\s*(error|warning|note):\s*(.+)$#m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => $m[4],
                    'file'    => $m[1],
                    'line'    => (int)$m[2],
                    'column'  => (int)$m[3],
                    'message' => trim($m[5]),
                    'severity'=> $m[4],
                ];
            }
        }

        return $errors;
    }

    // ── generic ───────────────────────────────────────────────

    private function parseGeneric(string $input): array
    {
        $errors = [];

        // file:line:col: message (catches most compilers)
        if (preg_match_all('/^(.+?):(\d+):(\d+):\s*(error|warning|fatal):\s*(.+)$/m', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'type'    => $m[4],
                    'file'    => $m[1],
                    'line'    => (int)$m[2],
                    'column'  => (int)$m[3],
                    'message' => trim($m[5]),
                    'severity'=> $m[4],
                ];
            }
        }

        return $errors;
    }

    // ── stack trace ───────────────────────────────────────────

    private function parseStackTrace(string $input, ?string $language): array
    {
        $frames = [];

        switch ($language) {
            case 'php':
                // #0 /path/file.php(42): ClassName->method()
                if (preg_match_all('/#(\d+)\s+(.+?)\((\d+)\):\s*(.+)/', $input, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $frames[] = ['index' => (int)$m[1], 'file' => $m[2], 'line' => (int)$m[3], 'call' => $m[4]];
                    }
                }
                break;

            case 'python':
                // File "path", line 42, in func
                if (preg_match_all('/File "(.+?)", line (\d+), in (\w+)/', $input, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $frames[] = ['file' => $m[1], 'line' => (int)$m[2], 'call' => $m[3]];
                    }
                }
                break;

            case 'node':
            case 'javascript':
            case 'typescript':
                // at functionName (/path/file.js:42:10)
                if (preg_match_all('/at\s+(.+?)\s+\((.+?):(\d+):(\d+)\)/', $input, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $frames[] = ['call' => $m[1], 'file' => $m[2], 'line' => (int)$m[3], 'column' => (int)$m[4]];
                    }
                }
                break;

            case 'java':
            case 'kotlin':
                // at com.pkg.Class.method(File.java:42)
                if (preg_match_all('/at\s+([\w.$]+)\(([\w.]+):(\d+)\)/', $input, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $frames[] = ['call' => $m[1], 'file' => $m[2], 'line' => (int)$m[3]];
                    }
                }
                break;

            case 'go':
                // /path/file.go:42 +0x1a2
                if (preg_match_all('#^(.+?\.go):(\d+)\s#m', $input, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $frames[] = ['file' => $m[1], 'line' => (int)$m[2]];
                    }
                }
                break;

            case 'ruby':
                // from /path/file.rb:42:in `method'
                if (preg_match_all("#from\s+(.+?\.rb):(\d+):in\s+`(.+?)'#", $input, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $frames[] = ['file' => $m[1], 'line' => (int)$m[2], 'call' => $m[3]];
                    }
                }
                break;
        }

        return $frames;
    }

    // ── helpers ───────────────────────────────────────────────

    private function hasFatal(string $input): bool
    {
        $patterns = ['/fatal/i', '/panic:/i', '/SIGABRT/', '/SIGSEGV/', '/Segmentation fault/', '/core dumped/'];
        foreach ($patterns as $p) {
            if (preg_match($p, $input)) return true;
        }
        return false;
    }

    private function extractNearbyMessage(string $input, string $anchor): string
    {
        $pos = strpos($input, $anchor);
        if ($pos === false) return '';
        $after = substr($input, $pos + strlen($anchor), 500);
        $lines = explode("\n", $after);
        // Look for Error: or similar in the next few lines
        foreach (array_slice($lines, 0, 5) as $line) {
            if (preg_match('/^\w*(?:Error|Exception):\s*(.+)/', $line, $m)) {
                return trim($m[0]);
            }
        }
        return trim($lines[0] ?? '');
    }
}
