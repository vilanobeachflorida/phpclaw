<?php

namespace App\Libraries\Tools;

/**
 * Auto-detect project stack, languages, frameworks, and toolchain.
 *
 * Scans a directory for marker files and returns a structured profile of:
 *   - Languages present
 *   - Framework / platform
 *   - Package manager and lock file status
 *   - Test framework
 *   - Linter / formatter
 *   - Build system
 *   - Entry points (if detectable)
 */
class ProjectDetectTool extends BaseTool
{
    protected string $name = 'project_detect';
    protected string $description = 'Auto-detect project languages, frameworks, test runners, linters, and build systems';

    protected function getDefaultConfig(): array
    {
        return ['enabled' => true, 'timeout' => 15];
    }

    public function getInputSchema(): array
    {
        return [
            'path' => ['type' => 'string', 'required' => false, 'description' => 'Directory to scan (defaults to cwd)'],
        ];
    }

    public function execute(array $args): array
    {
        $path = $args['path'] ?? getcwd();
        if (!is_dir($path)) return $this->error("Directory not found: {$path}");

        $files = $this->listTopLevel($path);

        $languages  = $this->detectLanguages($path, $files);
        $frameworks = $this->detectFrameworks($path, $files);
        $packages   = $this->detectPackageManagers($path, $files);
        $tests      = $this->detectTestFrameworks($path, $files);
        $linters    = $this->detectLinters($path, $files);
        $builders   = $this->detectBuildSystems($path, $files);
        $vcs        = $this->detectVCS($path, $files);

        return $this->success([
            'path'             => $path,
            'languages'        => $languages,
            'frameworks'       => $frameworks,
            'package_managers' => $packages,
            'test_frameworks'  => $tests,
            'linters'          => $linters,
            'build_systems'    => $builders,
            'vcs'              => $vcs,
        ]);
    }

    private function listTopLevel(string $path): array
    {
        $items = [];
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') continue;
            $items[] = $item;
        }
        return $items;
    }

    private function has(string $path, string $file): bool
    {
        return file_exists("{$path}/{$file}");
    }

    private function hasGlob(string $path, string $pattern): array
    {
        return glob("{$path}/{$pattern}") ?: [];
    }

    // ── languages ─────────────────────────────────────────────

    private function detectLanguages(string $path, array $files): array
    {
        $langs = [];
        $markers = [
            'PHP'        => ['composer.json', '*.php'],
            'JavaScript' => ['package.json', '*.js', '*.mjs'],
            'TypeScript' => ['tsconfig.json', '*.ts', '*.tsx'],
            'Python'     => ['pyproject.toml', 'setup.py', 'requirements.txt', 'Pipfile', '*.py'],
            'Go'         => ['go.mod', '*.go'],
            'Rust'       => ['Cargo.toml', '*.rs'],
            'Java'       => ['pom.xml', 'build.gradle', '*.java'],
            'Kotlin'     => ['build.gradle.kts', '*.kt'],
            'C#'         => ['*.csproj', '*.sln'],
            'Ruby'       => ['Gemfile', '*.rb'],
            'Swift'      => ['Package.swift', '*.swift'],
            'Dart'       => ['pubspec.yaml', '*.dart'],
            'Elixir'     => ['mix.exs', '*.ex'],
            'Zig'        => ['build.zig', '*.zig'],
            'C/C++'      => ['CMakeLists.txt', 'Makefile', '*.c', '*.cpp', '*.h'],
            'Lua'        => ['*.lua'],
            'Shell'      => ['*.sh', '*.bash'],
        ];

        foreach ($markers as $lang => $patterns) {
            foreach ($patterns as $p) {
                if (str_contains($p, '*')) {
                    if (!empty($this->hasGlob($path, $p))) {
                        $langs[] = $lang;
                        break;
                    }
                } elseif ($this->has($path, $p)) {
                    $langs[] = $lang;
                    break;
                }
            }
        }

        return array_values(array_unique($langs));
    }

    // ── frameworks ────────────────────────────────────────────

    private function detectFrameworks(string $path, array $files): array
    {
        $fw = [];

        // PHP frameworks
        if ($this->has($path, 'spark')) $fw[] = 'CodeIgniter';
        if ($this->has($path, 'artisan')) $fw[] = 'Laravel';
        if ($this->has($path, 'bin/console') && $this->has($path, 'symfony.lock')) $fw[] = 'Symfony';
        if ($this->has($path, 'wp-config.php') || $this->has($path, 'wp-content')) $fw[] = 'WordPress';
        if ($this->has($path, 'config/application.config.php')) $fw[] = 'Laminas';

        // JS frameworks
        if ($this->has($path, 'next.config.js') || $this->has($path, 'next.config.mjs') || $this->has($path, 'next.config.ts')) $fw[] = 'Next.js';
        if ($this->has($path, 'nuxt.config.ts') || $this->has($path, 'nuxt.config.js')) $fw[] = 'Nuxt';
        if ($this->has($path, 'angular.json')) $fw[] = 'Angular';
        if ($this->has($path, 'svelte.config.js')) $fw[] = 'SvelteKit';
        if ($this->has($path, 'astro.config.mjs') || $this->has($path, 'astro.config.ts')) $fw[] = 'Astro';
        if ($this->has($path, 'remix.config.js')) $fw[] = 'Remix';
        if ($this->has($path, 'vite.config.ts') || $this->has($path, 'vite.config.js')) $fw[] = 'Vite';

        // Python
        if ($this->has($path, 'manage.py')) $fw[] = 'Django';
        if ($this->hasGlob($path, '**/flask') || $this->grepFile($path, 'pyproject.toml', 'flask')) $fw[] = 'Flask';
        if ($this->grepFile($path, 'pyproject.toml', 'fastapi') || $this->grepFile($path, 'requirements.txt', 'fastapi')) $fw[] = 'FastAPI';

        // Ruby
        if ($this->has($path, 'config.ru') && $this->has($path, 'Gemfile')) $fw[] = 'Rails';

        // Go
        if ($this->has($path, 'go.mod') && $this->grepFile($path, 'go.mod', 'gin-gonic')) $fw[] = 'Gin';
        if ($this->has($path, 'go.mod') && $this->grepFile($path, 'go.mod', 'labstack/echo')) $fw[] = 'Echo';

        // Rust
        if ($this->grepFile($path, 'Cargo.toml', 'actix-web')) $fw[] = 'Actix';
        if ($this->grepFile($path, 'Cargo.toml', 'rocket')) $fw[] = 'Rocket';

        // Mobile
        if ($this->has($path, 'pubspec.yaml')) $fw[] = 'Flutter';
        if ($this->has($path, 'app.json') && $this->has($path, 'node_modules/expo')) $fw[] = 'Expo';

        return $fw;
    }

    // ── package managers ──────────────────────────────────────

    private function detectPackageManagers(string $path, array $files): array
    {
        $pm = [];
        $checks = [
            ['name' => 'composer',  'manifest' => 'composer.json',     'lock' => 'composer.lock'],
            ['name' => 'npm',       'manifest' => 'package.json',      'lock' => 'package-lock.json'],
            ['name' => 'yarn',      'manifest' => 'package.json',      'lock' => 'yarn.lock'],
            ['name' => 'pnpm',      'manifest' => 'package.json',      'lock' => 'pnpm-lock.yaml'],
            ['name' => 'bun',       'manifest' => 'package.json',      'lock' => 'bun.lockb'],
            ['name' => 'pip',       'manifest' => 'requirements.txt',  'lock' => null],
            ['name' => 'poetry',    'manifest' => 'pyproject.toml',    'lock' => 'poetry.lock'],
            ['name' => 'pipenv',    'manifest' => 'Pipfile',           'lock' => 'Pipfile.lock'],
            ['name' => 'uv',        'manifest' => 'pyproject.toml',    'lock' => 'uv.lock'],
            ['name' => 'cargo',     'manifest' => 'Cargo.toml',        'lock' => 'Cargo.lock'],
            ['name' => 'go_mod',    'manifest' => 'go.mod',            'lock' => 'go.sum'],
            ['name' => 'maven',     'manifest' => 'pom.xml',           'lock' => null],
            ['name' => 'gradle',    'manifest' => 'build.gradle',      'lock' => 'gradle.lockfile'],
            ['name' => 'bundler',   'manifest' => 'Gemfile',           'lock' => 'Gemfile.lock'],
            ['name' => 'pub',       'manifest' => 'pubspec.yaml',      'lock' => 'pubspec.lock'],
            ['name' => 'mix',       'manifest' => 'mix.exs',           'lock' => 'mix.lock'],
            ['name' => 'nuget',     'manifest' => '*.csproj',          'lock' => 'packages.lock.json'],
            ['name' => 'swift_pm',  'manifest' => 'Package.swift',     'lock' => 'Package.resolved'],
        ];

        foreach ($checks as $c) {
            $hasManifest = str_contains($c['manifest'], '*')
                ? !empty($this->hasGlob($path, $c['manifest']))
                : $this->has($path, $c['manifest']);

            if ($hasManifest) {
                $hasLock = $c['lock'] ? $this->has($path, $c['lock']) : null;
                $pm[] = [
                    'name'     => $c['name'],
                    'manifest' => $c['manifest'],
                    'lock'     => $c['lock'],
                    'has_lock' => $hasLock,
                ];
            }
        }

        return $pm;
    }

    // ── test frameworks ───────────────────────────────────────

    private function detectTestFrameworks(string $path, array $files): array
    {
        $tf = [];
        $checks = [
            ['marker' => 'phpunit.xml',        'name' => 'PHPUnit',   'command' => 'vendor/bin/phpunit'],
            ['marker' => 'phpunit.xml.dist',    'name' => 'PHPUnit',   'command' => 'vendor/bin/phpunit'],
            ['marker' => 'jest.config.js',      'name' => 'Jest',      'command' => 'npx jest'],
            ['marker' => 'jest.config.ts',      'name' => 'Jest',      'command' => 'npx jest'],
            ['marker' => 'vitest.config.ts',    'name' => 'Vitest',    'command' => 'npx vitest'],
            ['marker' => 'vitest.config.js',    'name' => 'Vitest',    'command' => 'npx vitest'],
            ['marker' => 'pytest.ini',          'name' => 'pytest',    'command' => 'python -m pytest'],
            ['marker' => 'pyproject.toml',      'name' => 'pytest',    'command' => 'python -m pytest', 'grep' => 'pytest'],
            ['marker' => 'setup.cfg',           'name' => 'pytest',    'command' => 'python -m pytest', 'grep' => 'pytest'],
            ['marker' => 'Cargo.toml',          'name' => 'cargo test', 'command' => 'cargo test'],
            ['marker' => 'go.mod',              'name' => 'go test',   'command' => 'go test ./...'],
            ['marker' => '.rspec',              'name' => 'RSpec',     'command' => 'bundle exec rspec'],
            ['marker' => 'Gemfile',             'name' => 'RSpec',     'command' => 'bundle exec rspec', 'grep' => 'rspec'],
            ['marker' => 'build.gradle',        'name' => 'JUnit',     'command' => './gradlew test'],
            ['marker' => 'pom.xml',             'name' => 'JUnit',     'command' => 'mvn test'],
            ['marker' => 'pubspec.yaml',        'name' => 'flutter test', 'command' => 'flutter test'],
            ['marker' => 'mix.exs',             'name' => 'ExUnit',    'command' => 'mix test'],
        ];

        $seen = [];
        foreach ($checks as $c) {
            if ($this->has($path, $c['marker'])) {
                if (isset($c['grep']) && !$this->grepFile($path, $c['marker'], $c['grep'])) continue;
                if (isset($seen[$c['name']])) continue;
                $seen[$c['name']] = true;
                $tf[] = ['name' => $c['name'], 'command' => $c['command'], 'marker' => $c['marker']];
            }
        }

        return $tf;
    }

    // ── linters ───────────────────────────────────────────────

    private function detectLinters(string $path, array $files): array
    {
        $linters = [];
        $checks = [
            ['marker' => 'phpstan.neon',         'name' => 'PHPStan',       'command' => 'vendor/bin/phpstan analyse --error-format=json'],
            ['marker' => 'phpstan.neon.dist',     'name' => 'PHPStan',       'command' => 'vendor/bin/phpstan analyse --error-format=json'],
            ['marker' => '.php-cs-fixer.php',     'name' => 'PHP-CS-Fixer',  'command' => 'vendor/bin/php-cs-fixer fix --dry-run --format=json'],
            ['marker' => '.php-cs-fixer.dist.php','name' => 'PHP-CS-Fixer',  'command' => 'vendor/bin/php-cs-fixer fix --dry-run --format=json'],
            ['marker' => 'psalm.xml',            'name' => 'Psalm',         'command' => 'vendor/bin/psalm --output-format=json'],
            ['marker' => '.eslintrc.js',         'name' => 'ESLint',        'command' => 'npx eslint --format json'],
            ['marker' => '.eslintrc.json',       'name' => 'ESLint',        'command' => 'npx eslint --format json'],
            ['marker' => '.eslintrc.cjs',        'name' => 'ESLint',        'command' => 'npx eslint --format json'],
            ['marker' => 'eslint.config.js',     'name' => 'ESLint',        'command' => 'npx eslint --format json'],
            ['marker' => 'eslint.config.mjs',    'name' => 'ESLint',        'command' => 'npx eslint --format json'],
            ['marker' => '.prettierrc',          'name' => 'Prettier',      'command' => 'npx prettier --check .'],
            ['marker' => '.prettierrc.json',     'name' => 'Prettier',      'command' => 'npx prettier --check .'],
            ['marker' => 'biome.json',           'name' => 'Biome',         'command' => 'npx biome check --reporter=json'],
            ['marker' => '.flake8',              'name' => 'Flake8',        'command' => 'flake8 --format json'],
            ['marker' => 'ruff.toml',            'name' => 'Ruff',          'command' => 'ruff check --output-format json'],
            ['marker' => '.golangci.yml',        'name' => 'golangci-lint', 'command' => 'golangci-lint run --out-format json'],
            ['marker' => '.golangci.yaml',       'name' => 'golangci-lint', 'command' => 'golangci-lint run --out-format json'],
            ['marker' => 'clippy.toml',          'name' => 'Clippy',        'command' => 'cargo clippy --message-format json'],
            ['marker' => '.rubocop.yml',         'name' => 'RuboCop',       'command' => 'bundle exec rubocop --format json'],
            ['marker' => 'analysis_options.yaml','name' => 'Dart Analyze',  'command' => 'dart analyze --format json'],
        ];

        // Also check pyproject.toml for ruff
        if ($this->has($path, 'pyproject.toml') && $this->grepFile($path, 'pyproject.toml', 'ruff')) {
            $linters[] = ['name' => 'Ruff', 'command' => 'ruff check --output-format json', 'marker' => 'pyproject.toml'];
        }

        $seen = [];
        foreach ($checks as $c) {
            if ($this->has($path, $c['marker'])) {
                if (isset($seen[$c['name']])) continue;
                $seen[$c['name']] = true;
                $linters[] = ['name' => $c['name'], 'command' => $c['command'], 'marker' => $c['marker']];
            }
        }

        return $linters;
    }

    // ── build systems ─────────────────────────────────────────

    private function detectBuildSystems(string $path, array $files): array
    {
        $bs = [];

        if ($this->has($path, 'Makefile') || $this->has($path, 'makefile')) $bs[] = ['name' => 'Make', 'command' => 'make'];
        if ($this->has($path, 'Taskfile.yml') || $this->has($path, 'Taskfile.yaml')) $bs[] = ['name' => 'Task', 'command' => 'task'];
        if ($this->has($path, 'Justfile')) $bs[] = ['name' => 'Just', 'command' => 'just'];
        if ($this->has($path, 'Dockerfile')) $bs[] = ['name' => 'Docker', 'command' => 'docker build .'];
        if ($this->has($path, 'docker-compose.yml') || $this->has($path, 'docker-compose.yaml') || $this->has($path, 'compose.yml') || $this->has($path, 'compose.yaml')) $bs[] = ['name' => 'Docker Compose', 'command' => 'docker compose up --build'];
        if ($this->has($path, 'CMakeLists.txt')) $bs[] = ['name' => 'CMake', 'command' => 'cmake --build .'];
        if ($this->has($path, 'webpack.config.js')) $bs[] = ['name' => 'Webpack', 'command' => 'npx webpack'];
        if ($this->has($path, 'vite.config.ts') || $this->has($path, 'vite.config.js')) $bs[] = ['name' => 'Vite', 'command' => 'npx vite build'];
        if ($this->has($path, 'turbo.json')) $bs[] = ['name' => 'Turborepo', 'command' => 'npx turbo run build'];
        if ($this->has($path, 'nx.json')) $bs[] = ['name' => 'Nx', 'command' => 'npx nx build'];
        if ($this->has($path, 'build.zig')) $bs[] = ['name' => 'Zig Build', 'command' => 'zig build'];

        return $bs;
    }

    // ── VCS ───────────────────────────────────────────────────

    private function detectVCS(string $path, array $files): array
    {
        $vcs = [];
        if ($this->has($path, '.git')) $vcs[] = 'git';
        if ($this->has($path, '.hg'))  $vcs[] = 'mercurial';
        if ($this->has($path, '.svn')) $vcs[] = 'svn';
        return $vcs;
    }

    // ── helpers ───────────────────────────────────────────────

    private function grepFile(string $dir, string $file, string $needle): bool
    {
        $fullPath = "{$dir}/{$file}";
        if (!file_exists($fullPath)) return false;
        $content = @file_get_contents($fullPath);
        return $content !== false && stripos($content, $needle) !== false;
    }
}
