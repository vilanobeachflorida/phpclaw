<?php

namespace App\Libraries\Storage;

/**
 * Core file-based storage layer.
 * All agent state, sessions, tasks, memory, and config go through this class.
 */
class FileStorage
{
    private string $basePath;

    public function __construct(string $basePath = WRITEPATH . 'agent')
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function path(string ...$parts): string
    {
        return $this->basePath . '/' . implode('/', $parts);
    }

    public function readJson(string $path): ?array
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath)) return null;
        $content = file_get_contents($fullPath);
        if ($content === false) return null;
        return json_decode($content, true);
    }

    public function writeJson(string $path, array $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): bool
    {
        $fullPath = $this->resolvePath($path);
        $this->ensureDir(dirname($fullPath));
        return file_put_contents($fullPath, json_encode($data, $flags) . "\n") !== false;
    }

    public function appendNdjson(string $path, array $record): bool
    {
        $fullPath = $this->resolvePath($path);
        $this->ensureDir(dirname($fullPath));
        $line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
        return file_put_contents($fullPath, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    public function readNdjson(string $path): array
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath)) return [];
        $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];
        $records = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if ($decoded !== null) {
                $records[] = $decoded;
            }
        }
        return $records;
    }

    public function readText(string $path): ?string
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath)) return null;
        $content = file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    public function writeText(string $path, string $content): bool
    {
        $fullPath = $this->resolvePath($path);
        $this->ensureDir(dirname($fullPath));
        return file_put_contents($fullPath, $content) !== false;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolvePath($path));
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->resolvePath($path);
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    public function listDir(string $path): array
    {
        $fullPath = $this->resolvePath($path);
        if (!is_dir($fullPath)) return [];
        $items = scandir($fullPath);
        return array_values(array_filter($items, fn($i) => $i !== '.' && $i !== '..'));
    }

    public function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public function countNdjsonLines(string $path): int
    {
        $fullPath = $this->resolvePath($path);
        if (!file_exists($fullPath)) return 0;
        $count = 0;
        $fh = fopen($fullPath, 'r');
        if ($fh) {
            while (fgets($fh) !== false) {
                $count++;
            }
            fclose($fh);
        }
        return $count;
    }

    /**
     * Acquire a file-based lock. Returns true if lock acquired.
     */
    public function acquireLock(string $name, int $ttl = 60): bool
    {
        $lockFile = $this->path('locks', $name . '.lock');
        $this->ensureDir(dirname($lockFile));

        if (file_exists($lockFile)) {
            $data = json_decode(file_get_contents($lockFile), true);
            if ($data && isset($data['expires_at']) && time() < $data['expires_at']) {
                return false; // Lock still held
            }
        }

        $data = ['acquired_at' => time(), 'expires_at' => time() + $ttl, 'pid' => getmypid()];
        return file_put_contents($lockFile, json_encode($data)) !== false;
    }

    public function releaseLock(string $name): void
    {
        $lockFile = $this->path('locks', $name . '.lock');
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    private function resolvePath(string $path): string
    {
        // If absolute path, use as-is; otherwise resolve relative to basePath
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $this->basePath . '/' . $path;
    }
}
