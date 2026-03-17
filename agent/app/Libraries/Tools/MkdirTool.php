<?php

namespace App\Libraries\Tools;

class MkdirTool extends BaseTool
{
    protected string $name = 'mkdir';
    protected string $description = 'Create a directory';

    public function getInputSchema(): array
    {
        return [
            'path' => ['type' => 'string', 'required' => true],
            'recursive' => ['type' => 'bool', 'required' => false, 'default' => true],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['path'])) return $err;

        if (is_dir($args['path'])) {
            return $this->success(['path' => $args['path'], 'created' => false], 'Directory already exists');
        }

        $recursive = $args['recursive'] ?? true;
        if (!mkdir($args['path'], 0755, $recursive)) {
            return $this->error("Failed to create directory: {$args['path']}");
        }

        return $this->success(['path' => $args['path'], 'created' => true]);
    }
}
