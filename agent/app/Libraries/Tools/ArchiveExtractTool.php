<?php

namespace App\Libraries\Tools;

/**
 * Create and extract archive files (ZIP, tar.gz, tar.bz2).
 */
class ArchiveExtractTool extends BaseTool
{
    protected string $name = 'archive_extract';
    protected string $description = 'Create and extract archive files (ZIP, tar.gz, tar.bz2)';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 60,
            'max_extract_size' => 104857600, // 100MB
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Action: "extract" or "create"',
            ],
            'archive_path' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Path to the archive file (to extract from or create)',
            ],
            'destination' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Destination directory for extraction (default: same directory as archive)',
            ],
            'files' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Array of file paths to include when creating an archive',
            ],
            'format' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Archive format: zip, tar.gz, tar.bz2 (auto-detected from extension for extract)',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['action', 'archive_path'])) return $err;

        $action = $args['action'];
        $archivePath = $args['archive_path'];

        return match ($action) {
            'extract' => $this->extractArchive($archivePath, $args['destination'] ?? null),
            'create'  => $this->createArchive($archivePath, $args['files'] ?? [], $args['format'] ?? null),
            default   => $this->error("Unknown action: {$action}. Use: extract, create"),
        };
    }

    private function extractArchive(string $archivePath, ?string $destination): array
    {
        if (!file_exists($archivePath)) {
            return $this->error("Archive not found: {$archivePath}");
        }

        $maxSize = $this->config['max_extract_size'] ?? 104857600;
        if (filesize($archivePath) > $maxSize) {
            return $this->error("Archive exceeds maximum size limit (" . round($maxSize / 1048576) . " MB)");
        }

        $destination = $destination ?? dirname($archivePath);
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $format = $this->detectFormat($archivePath);

        return match ($format) {
            'zip'     => $this->extractZip($archivePath, $destination),
            'tar.gz'  => $this->extractTar($archivePath, $destination, 'gz'),
            'tar.bz2' => $this->extractTar($archivePath, $destination, 'bz2'),
            'tar'     => $this->extractTar($archivePath, $destination, null),
            default   => $this->error("Unsupported archive format: {$format}"),
        };
    }

    private function createArchive(string $archivePath, array $files, ?string $format): array
    {
        if (empty($files)) {
            return $this->error("No files specified. Provide a 'files' array of paths to include.");
        }

        // Validate all files exist
        foreach ($files as $file) {
            if (!file_exists($file)) {
                return $this->error("File not found: {$file}");
            }
        }

        $format = $format ?? $this->detectFormat($archivePath);

        $dir = dirname($archivePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return match ($format) {
            'zip'     => $this->createZip($archivePath, $files),
            'tar.gz'  => $this->createTar($archivePath, $files, 'gz'),
            'tar.bz2' => $this->createTar($archivePath, $files, 'bz2'),
            'tar'     => $this->createTar($archivePath, $files, null),
            default   => $this->error("Unsupported archive format: {$format}"),
        };
    }

    private function extractZip(string $archivePath, string $destination): array
    {
        if (!class_exists('ZipArchive')) {
            return $this->error("ZipArchive extension not available. Install php-zip.");
        }

        $zip = new \ZipArchive();
        $result = $zip->open($archivePath);
        if ($result !== true) {
            return $this->error("Failed to open ZIP: error code {$result}");
        }

        $fileCount = $zip->numFiles;
        $fileList = [];
        for ($i = 0; $i < min($fileCount, 100); $i++) {
            $fileList[] = $zip->getNameIndex($i);
        }

        $zip->extractTo($destination);
        $zip->close();

        return $this->success([
            'action' => 'extract',
            'archive' => $archivePath,
            'format' => 'zip',
            'destination' => $destination,
            'file_count' => $fileCount,
            'files' => $fileList,
        ]);
    }

    private function extractTar(string $archivePath, string $destination, ?string $compression): array
    {
        $flags = match ($compression) {
            'gz'  => 'xzf',
            'bz2' => 'xjf',
            default => 'xf',
        };

        $cmd = sprintf('tar -%s %s -C %s 2>&1',
            $flags,
            escapeshellarg($archivePath),
            escapeshellarg($destination)
        );

        $output = shell_exec($cmd);
        if ($output === null || $output === false) {
            return $this->error("tar extraction failed");
        }

        // List contents
        $listFlags = match ($compression) {
            'gz'  => 'tzf',
            'bz2' => 'tjf',
            default => 'tf',
        };
        $listCmd = sprintf('tar -%s %s 2>&1', $listFlags, escapeshellarg($archivePath));
        $listing = shell_exec($listCmd);
        $files = array_filter(explode("\n", trim($listing ?? '')));

        return $this->success([
            'action' => 'extract',
            'archive' => $archivePath,
            'format' => $compression ? "tar.{$compression}" : 'tar',
            'destination' => $destination,
            'file_count' => count($files),
            'files' => array_slice($files, 0, 100),
        ]);
    }

    private function createZip(string $archivePath, array $files): array
    {
        if (!class_exists('ZipArchive')) {
            return $this->error("ZipArchive extension not available. Install php-zip.");
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->error("Failed to create ZIP: {$archivePath}");
        }

        $addedCount = 0;
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->addDirectoryToZip($zip, $file, basename($file));
            } else {
                $zip->addFile($file, basename($file));
            }
            $addedCount++;
        }

        $zip->close();

        return $this->success([
            'action' => 'create',
            'archive' => $archivePath,
            'format' => 'zip',
            'files_added' => $addedCount,
            'size' => filesize($archivePath),
        ]);
    }

    private function createTar(string $archivePath, array $files, ?string $compression): array
    {
        $flags = match ($compression) {
            'gz'  => 'czf',
            'bz2' => 'cjf',
            default => 'cf',
        };

        $fileArgs = implode(' ', array_map('escapeshellarg', $files));
        $cmd = sprintf('tar -%s %s %s 2>&1',
            $flags,
            escapeshellarg($archivePath),
            $fileArgs
        );

        $output = shell_exec($cmd);

        if (!file_exists($archivePath)) {
            return $this->error("Failed to create tar archive: " . ($output ?? 'unknown error'));
        }

        return $this->success([
            'action' => 'create',
            'archive' => $archivePath,
            'format' => $compression ? "tar.{$compression}" : 'tar',
            'files_added' => count($files),
            'size' => filesize($archivePath),
        ]);
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $localPath = $prefix . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                $zip->addEmptyDir($localPath);
            } else {
                $zip->addFile($item->getPathname(), $localPath);
            }
        }
    }

    private function detectFormat(string $path): string
    {
        if (str_ends_with($path, '.tar.gz') || str_ends_with($path, '.tgz')) return 'tar.gz';
        if (str_ends_with($path, '.tar.bz2') || str_ends_with($path, '.tbz2')) return 'tar.bz2';
        if (str_ends_with($path, '.tar')) return 'tar';
        if (str_ends_with($path, '.zip')) return 'zip';
        return pathinfo($path, PATHINFO_EXTENSION);
    }
}
