<?php

namespace App\Libraries\Tools;

/**
 * Example tool demonstrating the tool template pattern.
 * Returns input arguments as echo for testing.
 */
class ExampleTool extends BaseTool
{
    protected string $name = 'example';
    protected string $description = 'Example echo tool for testing and reference';

    public function getInputSchema(): array
    {
        return [
            'message' => ['type' => 'string', 'required' => true, 'description' => 'Message to echo back'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['message'])) return $err;

        $this->beforeExecute($args);
        $result = $this->success(['echo' => $args['message']]);
        $this->afterExecute($args, $result);

        return $result;
    }
}
