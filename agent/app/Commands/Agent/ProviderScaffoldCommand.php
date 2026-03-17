<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ProviderScaffoldCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:provider:scaffold';
    protected $description = 'Scaffold a new provider from template';
    protected $usage = 'agent:provider:scaffold <name>';

    public function run(array $params)
    {
        $name = $params[0] ?? null;
        if (!$name) {
            CLI::error('Usage: php spark agent:provider:scaffold <name>');
            return;
        }

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $slug))) . 'Provider';
        $displayName = str_replace('_', ' ', ucwords($slug, '_'));
        $upper = strtoupper($slug);

        $template = file_get_contents(ROOTPATH . 'templates/providers/CustomProviderTemplate.php.stub');
        if (!$template) {
            CLI::error('Template not found: templates/providers/CustomProviderTemplate.php.stub');
            return;
        }

        $content = str_replace(
            ['{{PROVIDER_SLUG}}', '{{PROVIDER_CLASS}}', '{{PROVIDER_NAME}}', '{{PROVIDER_DESCRIPTION}}', '{{PROVIDER_UPPER}}'],
            [$slug, $className, $displayName, "Custom provider: {$displayName}", $upper],
            $template
        );

        $outputPath = APPPATH . "Libraries/Providers/{$className}.php";
        if (file_exists($outputPath)) {
            CLI::error("File already exists: {$outputPath}");
            return;
        }

        file_put_contents($outputPath, $content);
        CLI::write("Provider scaffolded: {$outputPath}", 'green');
        CLI::write("Class: {$className}");
        CLI::write("Don't forget to add it to providers.json config.", 'yellow');
    }
}
