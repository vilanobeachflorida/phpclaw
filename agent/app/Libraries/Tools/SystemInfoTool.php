<?php

namespace App\Libraries\Tools;

class SystemInfoTool extends BaseTool
{
    protected string $name = 'system_info';
    protected string $description = 'Get system information';

    public function execute(array $args): array
    {
        return $this->success([
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'hostname' => gethostname(),
            'uname' => php_uname(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'disk_free' => disk_free_space('.'),
            'disk_total' => disk_total_space('.'),
            'load_avg' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            'uptime' => @file_get_contents('/proc/uptime'),
            'cwd' => getcwd(),
        ]);
    }
}
