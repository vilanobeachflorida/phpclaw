<?php

namespace App\Libraries\Tools;

class DeleteFileTool extends BaseTool
{
    protected string $name = 'delete_file';
    protected string $description = 'Delete a file';

    public function getInputSchema(): array
    {
        return [
            'path' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['path'])) return $err;

        if (!file_exists($args['path'])) {
            return $this->error("File not found: {$args['path']}");
        }

        if (is_dir($args['path'])) {
            return $this->error("Path is a directory, not a file: {$args['path']}");
        }

        if (!unlink($args['path'])) {
            return $this->error("Failed to delete: {$args['path']}");
        }

        return $this->success(['path' => $args['path'], 'deleted' => true]);
    }
}
