<?php

namespace App\Libraries\Tools;

class FileWriteTool extends BaseTool
{
    protected string $name = 'file_write';
    protected string $description = 'Write content to a file';

    public function getInputSchema(): array
    {
        return [
            'path' => ['type' => 'string', 'required' => true, 'description' => 'File path to write'],
            'content' => ['type' => 'string', 'required' => true, 'description' => 'Content to write'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['path', 'content'])) return $err;

        $dir = dirname($args['path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytes = file_put_contents($args['path'], $args['content']);
        if ($bytes === false) {
            return $this->error("Failed to write to: {$args['path']}");
        }

        return $this->success(['path' => $args['path'], 'bytes_written' => $bytes]);
    }
}
