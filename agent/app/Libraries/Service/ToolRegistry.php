<?php

namespace App\Libraries\Service;

use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Tools\ToolInterface;
use App\Libraries\Tools\{
    FileReadTool, FileWriteTool, FileAppendTool,
    DirListTool, MkdirTool, MoveFileTool, DeleteFileTool,
    GrepSearchTool, HttpGetTool, BrowserFetchTool, BrowserTextTool,
    ShellExecTool, SystemInfoTool,
    MemoryWriteTool, MemoryReadTool,
    GitOpsTool, CodePatchTool, DbQueryTool, ImageGenerateTool,
    CronScheduleTool, DiffReviewTool, HttpRequestTool,
    ArchiveExtractTool, ProcessManagerTool, NotificationSendTool
};

/**
 * Registry for all available tools.
 */
class ToolRegistry
{
    private array $tools = [];
    private ConfigLoader $config;

    private static array $builtinTools = [
        'file_read' => FileReadTool::class,
        'file_write' => FileWriteTool::class,
        'file_append' => FileAppendTool::class,
        'dir_list' => DirListTool::class,
        'mkdir' => MkdirTool::class,
        'move_file' => MoveFileTool::class,
        'delete_file' => DeleteFileTool::class,
        'grep_search' => GrepSearchTool::class,
        'http_get' => HttpGetTool::class,
        'browser_fetch' => BrowserFetchTool::class,
        'browser_text' => BrowserTextTool::class,
        'shell_exec' => ShellExecTool::class,
        'system_info' => SystemInfoTool::class,
        'memory_write' => MemoryWriteTool::class,
        'memory_read' => MemoryReadTool::class,
        'git_ops' => GitOpsTool::class,
        'code_patch' => CodePatchTool::class,
        'db_query' => DbQueryTool::class,
        'image_generate' => ImageGenerateTool::class,
        'cron_schedule' => CronScheduleTool::class,
        'diff_review' => DiffReviewTool::class,
        'http_request' => HttpRequestTool::class,
        'archive_extract' => ArchiveExtractTool::class,
        'process_manager' => ProcessManagerTool::class,
        'notification_send' => NotificationSendTool::class,
    ];

    public function __construct(?ConfigLoader $config = null)
    {
        $this->config = $config ?? new ConfigLoader();
    }

    /**
     * Load all enabled tools.
     */
    public function loadAll(): void
    {
        $toolsConfig = $this->config->get('tools', 'tools', []);

        foreach (self::$builtinTools as $name => $class) {
            $config = $toolsConfig[$name] ?? ['enabled' => true];
            if (!($config['enabled'] ?? true)) continue;

            $this->tools[$name] = new $class($config);
        }
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function register(string $name, ToolInterface $tool): void
    {
        $this->tools[$name] = $tool;
    }

    public function execute(string $name, array $args): array
    {
        $tool = $this->get($name);
        if (!$tool) {
            return ['success' => false, 'error' => "Tool not found: {$name}"];
        }
        if (!$tool->isEnabled()) {
            return ['success' => false, 'error' => "Tool disabled: {$name}"];
        }
        return $tool->execute($args);
    }

    public function listAll(): array
    {
        $result = [];
        foreach ($this->tools as $name => $tool) {
            $result[] = [
                'name' => $name,
                'description' => $tool->getDescription(),
                'enabled' => $tool->isEnabled(),
                'schema' => $tool->getInputSchema(),
            ];
        }
        return $result;
    }
}
