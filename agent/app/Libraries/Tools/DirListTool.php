<?php

namespace App\Libraries\Tools;

class DirListTool extends BaseTool
{
    protected string $name = 'dir_list';
    protected string $description = 'List directory contents';

    public function getInputSchema(): array
    {
        return [
            'path' => ['type' => 'string', 'required' => true],
            'recursive' => ['type' => 'bool', 'required' => false, 'default' => false],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['path'])) return $err;
        $path = $args['path'];

        if (!is_dir($path)) {
            return $this->error("Directory not found: {$path}");
        }

        $entries = [];
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = rtrim($path, '/') . '/' . $item;
            $entries[] = [
                'name' => $item,
                'path' => $fullPath,
                'type' => is_dir($fullPath) ? 'directory' : 'file',
                'size' => is_file($fullPath) ? filesize($fullPath) : null,
                'modified' => date('c', filemtime($fullPath)),
            ];
        }

        return $this->success(['path' => $path, 'entries' => $entries, 'count' => count($entries)]);
    }
}
