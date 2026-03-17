<?php

namespace App\Libraries\Cache;

use App\Libraries\Storage\FileStorage;

/**
 * File-based cache manager.
 * Supports LLM response caching, tool result caching, browser caching, etc.
 */
class CacheManager
{
    private FileStorage $storage;
    private array $config;

    public function __construct(?FileStorage $storage = null, array $config = [])
    {
        $this->storage = $storage ?? new FileStorage();
        $this->config = array_merge([
            'enabled' => true,
            'default_ttl' => 3600,
            'max_size_mb' => 500,
        ], $config);
    }

    /**
     * Get a cached value.
     */
    public function get(string $category, string $key): ?array
    {
        if (!$this->config['enabled']) return null;

        $path = $this->cachePath($category, $key);
        $entry = $this->storage->readJson($path);

        if (!$entry) return null;

        // Check expiration
        if (isset($entry['expires_at']) && time() > $entry['expires_at']) {
            $this->storage->delete($this->storage->path($path));
            return null;
        }

        return $entry['data'] ?? null;
    }

    /**
     * Store a value in cache.
     */
    public function set(string $category, string $key, $data, int $ttl = null, array $meta = []): bool
    {
        if (!$this->config['enabled']) return false;

        $ttl = $ttl ?? $this->config['default_ttl'];
        $entry = [
            'key' => $key,
            'category' => $category,
            'data' => $data,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'metadata' => $meta,
        ];

        return $this->storage->writeJson($this->cachePath($category, $key), $entry);
    }

    /**
     * Delete a cached value.
     */
    public function delete(string $category, string $key): bool
    {
        $path = $this->storage->path($this->cachePath($category, $key));
        if (file_exists($path)) {
            return unlink($path);
        }
        return false;
    }

    /**
     * Clear all entries in a category.
     */
    public function clearCategory(string $category): int
    {
        $dir = $this->storage->path('cache', $category);
        if (!is_dir($dir)) return 0;

        $count = 0;
        $files = glob($dir . '/*.json');
        foreach ($files as $file) {
            if (unlink($file)) $count++;
        }
        return $count;
    }

    /**
     * Clear all cache.
     */
    public function clearAll(): int
    {
        $total = 0;
        $categories = ['llm', 'tools', 'browser', 'providers'];
        foreach ($categories as $cat) {
            $total += $this->clearCategory($cat);
        }
        return $total;
    }

    /**
     * Prune expired entries.
     */
    public function prune(): array
    {
        $pruned = 0;
        $checked = 0;
        $categories = ['llm', 'tools', 'browser', 'providers'];

        foreach ($categories as $category) {
            $dir = $this->storage->path('cache', $category);
            if (!is_dir($dir)) continue;

            $files = glob($dir . '/*.json');
            foreach ($files as $file) {
                $checked++;
                $content = file_get_contents($file);
                $entry = json_decode($content, true);
                if ($entry && isset($entry['expires_at']) && time() > $entry['expires_at']) {
                    unlink($file);
                    $pruned++;
                }
            }
        }

        return ['checked' => $checked, 'pruned' => $pruned];
    }

    /**
     * Get cache status/stats.
     */
    public function getStatus(): array
    {
        $stats = ['enabled' => $this->config['enabled'], 'categories' => []];
        $categories = ['llm', 'tools', 'browser', 'providers'];

        foreach ($categories as $category) {
            $dir = $this->storage->path('cache', $category);
            $files = is_dir($dir) ? glob($dir . '/*.json') : [];
            $size = 0;
            foreach ($files as $f) {
                $size += filesize($f);
            }
            $stats['categories'][$category] = [
                'entries' => count($files),
                'size_bytes' => $size,
            ];
        }

        return $stats;
    }

    private function cachePath(string $category, string $key): string
    {
        $hash = md5($key);
        return "cache/{$category}/{$hash}.json";
    }
}
