<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\UI\TerminalUI;

class ConfigCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:config';
    protected $description = 'Show agent configuration';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $config = new ConfigLoader();
        $name = $params[0] ?? null;

        if ($name) {
            $data = $config->load($name);
            $ui->header("Config: {$name}");
            $ui->newLine();

            // Pretty print JSON with syntax highlighting
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            foreach (explode("\n", $json) as $line) {
                // Highlight keys
                $line = preg_replace_callback('/"([^"]+)":/', function($m) use ($ui) {
                    return $ui->style("\"{$m[1]}\"", 'bright_cyan') . ':';
                }, $line);
                // Highlight string values
                $line = preg_replace_callback('/: "([^"]*)"/', function($m) use ($ui) {
                    return ': ' . $ui->style("\"{$m[1]}\"", 'bright_green');
                }, $line);
                // Highlight numbers
                $line = preg_replace_callback('/: (\d+)/', function($m) use ($ui) {
                    return ': ' . $ui->style($m[1], 'bright_yellow');
                }, $line);
                // Highlight booleans
                $line = preg_replace_callback('/: (true|false|null)/', function($m) use ($ui) {
                    return ': ' . $ui->style($m[1], 'bright_magenta');
                }, $line);
                echo "  {$line}\n";
            }
            $ui->newLine();
        } else {
            $ui->header('Configuration');
            $ui->newLine();

            $configs = ['app', 'roles', 'modules', 'providers', 'tools', 'service'];
            $rows = [];
            foreach ($configs as $c) {
                $rows[] = [
                    $ui->style($c, 'bright_cyan'),
                    "php spark agent:config {$c}",
                ];
            }
            $ui->table(['Config', 'Command'], $rows, 'blue');
            $ui->newLine();
        }
    }
}
