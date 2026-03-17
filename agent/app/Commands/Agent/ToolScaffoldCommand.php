<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ToolScaffoldCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:tool:scaffold';
    protected $description = 'Scaffold a new tool from template';
    protected $usage = 'agent:tool:scaffold <name>';

    public function run(array $params)
    {
        $name = $params[0] ?? null;
        if (!$name) {
            CLI::error('Usage: php spark agent:tool:scaffold <name>');
            return;
        }

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $slug))) . 'Tool';
        $displayName = str_replace('_', ' ', ucwords($slug, '_'));

        $template = file_get_contents(ROOTPATH . 'templates/tools/CustomToolTemplate.php.stub');
        if (!$template) {
            CLI::error('Template not found: templates/tools/CustomToolTemplate.php.stub');
            return;
        }

        $content = str_replace(
            ['{{TOOL_SLUG}}', '{{TOOL_CLASS}}', '{{TOOL_NAME}}', '{{TOOL_DESCRIPTION}}'],
            [$slug, $className, $displayName, "Custom tool: {$displayName}"],
            $template
        );

        $outputPath = APPPATH . "Libraries/Tools/{$className}.php";
        if (file_exists($outputPath)) {
            CLI::error("File already exists: {$outputPath}");
            return;
        }

        file_put_contents($outputPath, $content);
        CLI::write("Tool scaffolded: {$outputPath}", 'green');
        CLI::write("Class: {$className}");
        CLI::write("Don't forget to register it in tools.json config.", 'yellow');
    }
}
