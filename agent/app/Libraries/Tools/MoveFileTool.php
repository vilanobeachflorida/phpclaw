<?php

namespace App\Libraries\Tools;

class MoveFileTool extends BaseTool
{
    protected string $name = 'move_file';
    protected string $description = 'Move or rename a file';

    public function getInputSchema(): array
    {
        return [
            'source' => ['type' => 'string', 'required' => true],
            'destination' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['source', 'destination'])) return $err;

        if (!file_exists($args['source'])) {
            return $this->error("Source not found: {$args['source']}");
        }

        $destDir = dirname($args['destination']);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        if (!rename($args['source'], $args['destination'])) {
            return $this->error("Failed to move {$args['source']} to {$args['destination']}");
        }

        return $this->success(['source' => $args['source'], 'destination' => $args['destination']]);
    }
}
