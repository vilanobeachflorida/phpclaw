<?php

namespace App\Libraries\Storage;

/**
 * Loads and merges all agent configuration from JSON files.
 * Config files live in writable/agent/config/.
 */
class ConfigLoader
{
    private FileStorage $storage;
    private array $cache = [];

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
    }

    public function load(string $name): array
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $data = $this->storage->readJson("config/{$name}.json");
        $this->cache[$name] = $data ?? [];
        return $this->cache[$name];
    }

    public function get(string $name, string $key = null, $default = null)
    {
        $data = $this->load($name);
        if ($key === null) return $data;

        // Support dot notation: "service.loop_interval_ms"
        $keys = explode('.', $key);
        $value = $data;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public function save(string $name, array $data): bool
    {
        $this->cache[$name] = $data;
        return $this->storage->writeJson("config/{$name}.json", $data);
    }

    public function reload(string $name = null): void
    {
        if ($name) {
            unset($this->cache[$name]);
        } else {
            $this->cache = [];
        }
    }

    public function all(): array
    {
        $configs = ['app', 'roles', 'modules', 'providers', 'tools', 'service'];
        $all = [];
        foreach ($configs as $name) {
            $all[$name] = $this->load($name);
        }
        return $all;
    }
}
