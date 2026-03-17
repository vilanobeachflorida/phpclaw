<?php

namespace App\Libraries\Tools;

class GrepSearchTool extends BaseTool
{
    protected string $name = 'grep_search';
    protected string $description = 'Search file contents with regex patterns';

    public function getInputSchema(): array
    {
        return [
            'pattern' => ['type' => 'string', 'required' => true, 'description' => 'Regex pattern to search for'],
            'path' => ['type' => 'string', 'required' => true, 'description' => 'File or directory to search in'],
            'recursive' => ['type' => 'bool', 'required' => false, 'default' => true],
            'max_results' => ['type' => 'int', 'required' => false, 'default' => 100],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['pattern', 'path'])) return $err;

        $path = $args['path'];
        $pattern = $args['pattern'];
        $maxResults = (int)($args['max_results'] ?? 100);

        if (!file_exists($path)) {
            return $this->error("Path not found: {$path}");
        }

        $matches = [];
        if (is_file($path)) {
            $matches = $this->searchFile($path, $pattern, $maxResults);
        } else {
            $matches = $this->searchDirectory($path, $pattern, $args['recursive'] ?? true, $maxResults);
        }

        return $this->success([
            'pattern' => $pattern,
            'path' => $path,
            'matches' => $matches,
            'count' => count($matches),
        ]);
    }

    private function searchFile(string $file, string $pattern, int $max): array
    {
        $matches = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return [];

        foreach ($lines as $num => $line) {
            if (count($matches) >= $max) break;
            if (@preg_match('/' . $pattern . '/i', $line)) {
                $matches[] = [
                    'file' => $file,
                    'line' => $num + 1,
                    'content' => $line,
                ];
            }
        }
        return $matches;
    }

    private function searchDirectory(string $dir, string $pattern, bool $recursive, int $max): array
    {
        $matches = [];
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS))
            : new \DirectoryIterator($dir);

        foreach ($iterator as $file) {
            if (count($matches) >= $max) break;
            if ($file->isFile() && $file->isReadable()) {
                $ext = $file->getExtension();
                // Skip binary files
                if (in_array($ext, ['jpg', 'png', 'gif', 'pdf', 'zip', 'gz', 'tar', 'exe', 'bin', 'so'])) continue;
                $fileMatches = $this->searchFile($file->getPathname(), $pattern, $max - count($matches));
                $matches = array_merge($matches, $fileMatches);
            }
        }
        return $matches;
    }
}
