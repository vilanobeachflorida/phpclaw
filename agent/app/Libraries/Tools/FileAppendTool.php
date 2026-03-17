<?php

namespace App\Libraries\Tools;

class FileAppendTool extends BaseTool
{
    protected string $name = 'file_append';
    protected string $description = 'Append content to a file';

    public function getInputSchema(): array
    {
        return [
            'path' => ['type' => 'string', 'required' => true],
            'content' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['path', 'content'])) return $err;

        $dir = dirname($args['path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytes = file_put_contents($args['path'], $args['content'], FILE_APPEND);
        if ($bytes === false) {
            return $this->error("Failed to append to: {$args['path']}");
        }

        return $this->success(['path' => $args['path'], 'bytes_written' => $bytes]);
    }
}
