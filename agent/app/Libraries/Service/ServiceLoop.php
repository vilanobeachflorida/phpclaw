<?php

namespace App\Libraries\Service;

use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Tasks\TaskManager;
use App\Libraries\Memory\MemoryManager;
use App\Libraries\Cache\CacheManager;

/**
 * Core service loop: runs continuously, processes tasks, performs maintenance.
 * Designed to run under systemd or similar process manager.
 */
class ServiceLoop
{
    private FileStorage $storage;
    private ConfigLoader $config;
    private TaskManager $tasks;
    private MemoryManager $memory;
    private CacheManager $cache;
    private bool $running = false;
    private int $iteration = 0;

    public function __construct()
    {
        $this->storage = new FileStorage();
        $this->config = new ConfigLoader($this->storage);
        $this->tasks = new TaskManager($this->storage);
        $this->memory = new MemoryManager($this->storage);
        $this->cache = new CacheManager($this->storage);
    }

    public function start(): void
    {
        $this->running = true;
        $this->updateState('running');
        $this->writePid();
        $this->log('Service started');

        $serviceConfig = $this->config->load('service')['service'] ?? [];
        $loopInterval = ($serviceConfig['loop_interval_ms'] ?? 1000) * 1000; // Convert to microseconds
        $lastHeartbeat = 0;
        $lastMaintenance = 0;
        $lastHealthCheck = 0;

        $heartbeatInterval = $serviceConfig['heartbeat_interval'] ?? 60;
        $maintenanceInterval = $serviceConfig['maintenance_interval'] ?? 3600;
        $healthInterval = $serviceConfig['provider_health_interval'] ?? 300;

        // Handle signals for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () { $this->stop(); });
            pcntl_signal(SIGINT, function () { $this->stop(); });
        }

        while ($this->running) {
            $this->iteration++;
            $now = time();

            // Update loop state
            $this->storage->writeJson('state/loop.json', [
                'iteration' => $this->iteration,
                'last_tick' => date('c'),
                'status' => 'running',
            ]);

            // Process pending tasks
            $this->processTasks();

            // Heartbeat
            if ($now - $lastHeartbeat >= $heartbeatInterval) {
                $this->heartbeat();
                $lastHeartbeat = $now;
            }

            // Maintenance
            if ($now - $lastMaintenance >= $maintenanceInterval) {
                $this->maintenance();
                $lastMaintenance = $now;
            }

            // Provider health check
            if ($now - $lastHealthCheck >= $healthInterval) {
                $this->checkProviderHealth();
                $lastHealthCheck = $now;
            }

            // Dispatch signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep($loopInterval);
        }

        $this->updateState('stopped');
        $this->log('Service stopped');
    }

    public function stop(): void
    {
        $this->running = false;
        $this->log('Service stop requested');
    }

    private function processTasks(): void
    {
        $activeTasks = $this->tasks->getActiveTasks();
        foreach ($activeTasks as $task) {
            if ($task['status'] === 'queued') {
                $this->tasks->updateStatus($task['id'], 'running');
                $this->tasks->addProgress($task['id'], ['message' => 'Task started by service loop']);
                $this->log("Task started: {$task['id']}");
            }
        }
    }

    private function heartbeat(): void
    {
        $this->storage->writeJson('state/heartbeat.json', [
            'last_check' => date('c'),
            'status' => 'ok',
            'iteration' => $this->iteration,
        ]);
        $this->log('Heartbeat OK');
    }

    private function maintenance(): void
    {
        $this->log('Running maintenance...');

        // Memory compaction
        $result = $this->memory->compactGlobalMemory();
        if ($result['compacted'] ?? false) {
            $this->log("Memory compacted: {$result['note_count']} notes");
        }

        // Cache pruning
        $pruneResult = $this->cache->prune();
        if ($pruneResult['pruned'] > 0) {
            $this->log("Cache pruned: {$pruneResult['pruned']} entries");
        }

        $this->storage->appendNdjson('logs/maintenance.ndjson', [
            'timestamp' => date('c'),
            'memory_result' => $result,
            'cache_result' => $pruneResult,
        ]);
    }

    private function checkProviderHealth(): void
    {
        // Placeholder: iterate configured providers and check health
        $this->storage->writeJson('state/provider-health.json', [
            'last_check' => date('c'),
            'providers' => [],
        ]);
    }

    private function updateState(string $status): void
    {
        $this->storage->writeJson('state/service.json', [
            'status' => $status,
            'started_at' => $status === 'running' ? date('c') : null,
            'last_heartbeat' => date('c'),
            'iteration' => $this->iteration,
            'pid' => getmypid(),
        ]);
    }

    private function writePid(): void
    {
        $pidFile = $this->storage->path('state', 'service.pid');
        file_put_contents($pidFile, (string)getmypid());
    }

    private function log(string $message): void
    {
        $line = '[' . date('c') . '] ' . $message . "\n";
        $logFile = $this->storage->path('logs', 'service.log');
        file_put_contents($logFile, $line, FILE_APPEND);
    }
}
