<?php

namespace App\Libraries\Tools;

class FileReadTool extends BaseTool
{
    protected string $name = 'file_read';
    protected string $description = 'Read contents of a file';

    public function getInputSchema(): array
    {
        return [
            'path' => ['type' => 'string', 'required' => true, 'description' => 'File path to read'],
            'offset' => ['type' => 'int', 'required' => false, 'description' => 'Line offset to start reading from'],
            'limit' => ['type' => 'int', 'required' => false, 'description' => 'Maximum number of lines to read'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['path'])) return $err;
        $path = $args['path'];

        if (!file_exists($path)) {
            return $this->error("File not found: {$path}");
        }
        if (is_dir($path)) {
            return $this->error("Path is a directory, not a file: {$path}. Use dir_list instead.");
        }
        if (!is_readable($path)) {
            return $this->error("File not readable: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->error("Failed to read file: {$path}");
        }

        // Apply offset and limit if specified
        if (isset($args['offset']) || isset($args['limit'])) {
            $lines = explode("\n", $content);
            $offset = (int)($args['offset'] ?? 0);
            $limit = isset($args['limit']) ? (int)$args['limit'] : null;
            $lines = array_slice($lines, $offset, $limit);
            $content = implode("\n", $lines);
        }

        return $this->success([
            'path' => $path,
            'content' => $content,
            'size' => strlen($content),
        ]);
    }
}
