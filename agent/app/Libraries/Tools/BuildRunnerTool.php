<?php

namespace App\Libraries\Tools;

/**
 * Universal build and dependency management — language-agnostic.
 *
 * Detects and executes build systems, dependency installers, and dev servers.
 *
 * Actions:
 *   detect   – identify build system(s) in the project
 *   build    – execute the build command
 *   run      – start the project (dev server, main script, etc.)
 *   deps     – install or update dependencies
 *   clean    – run clean/reset commands
 *   script   – run a named script from the project (npm run X, composer X, make X, etc.)
 */
class BuildRunnerTool extends BaseTool
{
    protected string $name = 'build_runner';
    protected string $description = 'Detect and run build systems, install dependencies, and execute project scripts for any language';

    protected function getDefaultConfig(): array
    {
        return ['enabled' => true, 'timeout' => 120];
    }

    public function getInputSchema(): array
    {
        return [
            'action'     => ['type' => 'string', 'required' => true, 'enum' => ['detect', 'build', 'run', 'deps', 'clean', 'script']],
            'path'       => ['type' => 'string', 'required' => false, 'description' => 'Project directory (defaults to cwd)'],
            'tool'       => ['type' => 'string', 'required' => false, 'description' => 'Override build tool (e.g. npm, cargo, make)'],
            'script_name'=> ['type' => 'string', 'required' => false, 'description' => 'Script name (action=script)'],
            'extra_args' => ['type' => 'string', 'required' => false, 'description' => 'Additional CLI args'],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action'])) return $err;

        $path  = $args['path'] ?? getcwd();
        $tool  = $args['tool'] ?? null;
        $extra = $args['extra_args'] ?? '';

        switch ($args['action']) {
            case 'detect':
                return $this->detect($path);

            case 'build':
                return $this->runCommand($path, $this->getBuildCommand($path, $tool), $extra, 'build');

            case 'run':
                return $this->runCommand($path, $this->getRunCommand($path, $tool), $extra, 'run');

            case 'deps':
                return $this->runCommand($path, $this->getDepsCommand($path, $tool), $extra, 'deps');

            case 'clean':
                return $this->runCommand($path, $this->getCleanCommand($path, $tool), $extra, 'clean');

            case 'script':
                if ($err = $this->requireArgs($args, ['script_name'])) return $err;
                return $this->runScript($path, $tool, $args['script_name'], $extra);

            default:
                return $this->error("Unknown action: {$args['action']}");
        }
    }

    // ── detect ────────────────────────────────────────────────

    private function detect(string $path): array
    {
        $tools = [];

        $checks = [
            ['marker' => 'composer.json',       'name' => 'composer',  'lang' => 'PHP'],
            ['marker' => 'package.json',        'name' => 'npm',      'lang' => 'JavaScript'],
            ['marker' => 'yarn.lock',           'name' => 'yarn',     'lang' => 'JavaScript'],
            ['marker' => 'pnpm-lock.yaml',      'name' => 'pnpm',    'lang' => 'JavaScript'],
            ['marker' => 'bun.lockb',           'name' => 'bun',      'lang' => 'JavaScript'],
            ['marker' => 'Cargo.toml',          'name' => 'cargo',    'lang' => 'Rust'],
            ['marker' => 'go.mod',              'name' => 'go',       'lang' => 'Go'],
            ['marker' => 'pyproject.toml',      'name' => 'python',   'lang' => 'Python'],
            ['marker' => 'requirements.txt',    'name' => 'pip',      'lang' => 'Python'],
            ['marker' => 'Pipfile',             'name' => 'pipenv',   'lang' => 'Python'],
            ['marker' => 'Gemfile',             'name' => 'bundler',  'lang' => 'Ruby'],
            ['marker' => 'pubspec.yaml',        'name' => 'flutter',  'lang' => 'Dart'],
            ['marker' => 'mix.exs',             'name' => 'mix',      'lang' => 'Elixir'],
            ['marker' => 'build.gradle',        'name' => 'gradle',   'lang' => 'Java'],
            ['marker' => 'build.gradle.kts',    'name' => 'gradle',   'lang' => 'Kotlin'],
            ['marker' => 'pom.xml',             'name' => 'maven',    'lang' => 'Java'],
            ['marker' => 'Makefile',            'name' => 'make',     'lang' => 'Any'],
            ['marker' => 'Taskfile.yml',        'name' => 'task',     'lang' => 'Any'],
            ['marker' => 'Justfile',            'name' => 'just',     'lang' => 'Any'],
            ['marker' => 'Dockerfile',          'name' => 'docker',   'lang' => 'Any'],
            ['marker' => 'docker-compose.yml',  'name' => 'docker_compose', 'lang' => 'Any'],
            ['marker' => 'compose.yml',         'name' => 'docker_compose', 'lang' => 'Any'],
            ['marker' => 'CMakeLists.txt',      'name' => 'cmake',    'lang' => 'C/C++'],
            ['marker' => 'build.zig',           'name' => 'zig',      'lang' => 'Zig'],
            ['marker' => 'Package.swift',       'name' => 'swift_pm', 'lang' => 'Swift'],
        ];

        $seen = [];
        foreach ($checks as $c) {
            if (file_exists("{$path}/{$c['marker']}") && !isset($seen[$c['name']])) {
                $seen[$c['name']] = true;

                // Detect available scripts
                $scripts = $this->detectScripts($path, $c['name']);

                $tools[] = [
                    'name'     => $c['name'],
                    'language' => $c['lang'],
                    'marker'   => $c['marker'],
                    'scripts'  => $scripts,
                ];
            }
        }

        return $this->success(['tools' => $tools, 'count' => count($tools)]);
    }

    // ── script detection ──────────────────────────────────────

    private function detectScripts(string $path, string $tool): array
    {
        switch ($tool) {
            case 'npm':
            case 'yarn':
            case 'pnpm':
            case 'bun':
                $pkg = @json_decode(@file_get_contents("{$path}/package.json"), true);
                return array_keys($pkg['scripts'] ?? []);

            case 'composer':
                $cmp = @json_decode(@file_get_contents("{$path}/composer.json"), true);
                return array_keys($cmp['scripts'] ?? []);

            case 'make':
                $content = @file_get_contents("{$path}/Makefile");
                if (!$content) return [];
                preg_match_all('/^(\w[\w-]*)\s*:/m', $content, $m);
                return $m[1] ?? [];

            case 'task':
                $content = @file_get_contents("{$path}/Taskfile.yml");
                if (!$content) return [];
                preg_match_all('/^\s+(\w[\w-]*):\s*$/m', $content, $m);
                return $m[1] ?? [];

            case 'just':
                $content = @file_get_contents("{$path}/Justfile");
                if (!$content) return [];
                preg_match_all('/^(\w[\w-]*)\s*:/m', $content, $m);
                return $m[1] ?? [];

            default:
                return [];
        }
    }

    // ── command resolution ────────────────────────────────────

    private function getBuildCommand(string $path, ?string $tool): ?string
    {
        $t = $tool ?? $this->autoDetectTool($path);
        $map = [
            'npm'     => 'npm run build',
            'yarn'    => 'yarn build',
            'pnpm'    => 'pnpm build',
            'bun'     => 'bun run build',
            'cargo'   => 'cargo build',
            'go'      => 'go build ./...',
            'make'    => 'make',
            'cmake'   => 'cmake --build .',
            'gradle'  => './gradlew build',
            'maven'   => 'mvn package',
            'docker'  => 'docker build .',
            'docker_compose' => 'docker compose build',
            'zig'     => 'zig build',
            'swift_pm' => 'swift build',
            'flutter' => 'flutter build',
            'mix'     => 'mix compile',
        ];
        return $map[$t] ?? null;
    }

    private function getRunCommand(string $path, ?string $tool): ?string
    {
        $t = $tool ?? $this->autoDetectTool($path);
        $map = [
            'npm'     => 'npm start',
            'yarn'    => 'yarn start',
            'pnpm'    => 'pnpm start',
            'bun'     => 'bun start',
            'cargo'   => 'cargo run',
            'go'      => 'go run .',
            'python'  => 'python main.py',
            'pip'     => 'python main.py',
            'pipenv'  => 'pipenv run python main.py',
            'docker_compose' => 'docker compose up',
            'flutter' => 'flutter run',
            'mix'     => 'mix run',
            'gradle'  => './gradlew run',
        ];
        return $map[$t] ?? null;
    }

    private function getDepsCommand(string $path, ?string $tool): ?string
    {
        $t = $tool ?? $this->autoDetectTool($path);
        $map = [
            'composer' => 'composer install',
            'npm'      => 'npm install',
            'yarn'     => 'yarn install',
            'pnpm'     => 'pnpm install',
            'bun'      => 'bun install',
            'cargo'    => 'cargo fetch',
            'go'       => 'go mod download',
            'pip'      => 'pip install -r requirements.txt',
            'python'   => 'pip install -e .',
            'pipenv'   => 'pipenv install',
            'bundler'  => 'bundle install',
            'flutter'  => 'flutter pub get',
            'mix'      => 'mix deps.get',
            'maven'    => 'mvn dependency:resolve',
            'gradle'   => './gradlew dependencies',
            'swift_pm' => 'swift package resolve',
        ];
        return $map[$t] ?? null;
    }

    private function getCleanCommand(string $path, ?string $tool): ?string
    {
        $t = $tool ?? $this->autoDetectTool($path);
        $map = [
            'cargo'   => 'cargo clean',
            'go'      => 'go clean',
            'make'    => 'make clean',
            'gradle'  => './gradlew clean',
            'maven'   => 'mvn clean',
            'cmake'   => 'cmake --build . --target clean',
            'flutter' => 'flutter clean',
            'mix'     => 'mix clean',
            'zig'     => 'zig build --clean',
        ];
        return $map[$t] ?? null;
    }

    private function autoDetectTool(string $path): ?string
    {
        $detect = $this->detect($path);
        $tools = $detect['data']['tools'] ?? [];
        return $tools[0]['name'] ?? null;
    }

    // ── run script ────────────────────────────────────────────

    private function runScript(string $path, ?string $tool, string $scriptName, string $extra): array
    {
        $t = $tool ?? $this->autoDetectTool($path);
        if (!$t) return $this->error('No build tool detected. Use tool param.');

        $map = [
            'npm'      => "npm run {$scriptName}",
            'yarn'     => "yarn {$scriptName}",
            'pnpm'     => "pnpm {$scriptName}",
            'bun'      => "bun run {$scriptName}",
            'composer' => "composer {$scriptName}",
            'make'     => "make {$scriptName}",
            'task'     => "task {$scriptName}",
            'just'     => "just {$scriptName}",
            'gradle'   => "./gradlew {$scriptName}",
        ];

        $cmd = $map[$t] ?? "{$t} {$scriptName}";
        if ($extra) $cmd .= ' ' . $extra;

        return $this->runCommand($path, $cmd, '', 'script');
    }

    // ── execution ─────────────────────────────────────────────

    private function runCommand(string $path, ?string $command, string $extra, string $action): array
    {
        if (!$command) return $this->error("No {$action} command found for this project. Use tool param to specify.");

        if ($extra) $command .= ' ' . $extra;

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $path);
        if (!is_resource($process)) return $this->error("Failed to start: {$command}");

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 10_485_760);
        $stderr = stream_get_contents($pipes[2], 10_485_760);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return $this->success([
            'action'    => $action,
            'command'   => $command,
            'exit_code' => $exitCode,
            'stdout'    => mb_strlen($stdout) > 16384 ? mb_substr($stdout, 0, 16384) . "\n... (truncated)" : $stdout,
            'stderr'    => mb_strlen($stderr) > 8192 ? mb_substr($stderr, 0, 8192) . "\n... (truncated)" : $stderr,
        ]);
    }
}
