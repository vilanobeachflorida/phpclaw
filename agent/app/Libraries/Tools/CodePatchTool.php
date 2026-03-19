<?php

namespace App\Libraries\Tools;

/**
 * Surgical code editing via exact string replacement.
 * Safer than file_write for modifications — avoids reproducing entire files.
 */
class CodePatchTool extends BaseTool
{
    protected string $name = 'code_patch';
    protected string $description = 'Edit a file by replacing an exact string match with new content (safer than full file rewrites)';

    public function getInputSchema(): array
    {
        return [
            'path' => [
                'type' => 'string',
                'required' => true,
                'description' => 'File path to edit',
            ],
            'old_string' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Exact string to find and replace (must be unique in the file)',
            ],
            'new_string' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Replacement string',
            ],
            'replace_all' => [
                'type' => 'bool',
                'required' => false,
                'description' => 'Replace all occurrences instead of requiring uniqueness (default: false)',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['path', 'old_string', 'new_string'])) return $err;

        $path = $args['path'];
        $oldString = $args['old_string'];
        $newString = $args['new_string'];
        $replaceAll = (bool)($args['replace_all'] ?? false);

        if (!file_exists($path)) {
            return $this->error("File not found: {$path}");
        }

        if (!is_writable($path)) {
            return $this->error("File not writable: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->error("Failed to read file: {$path}");
        }

        if ($oldString === $newString) {
            return $this->error("old_string and new_string are identical — nothing to change");
        }

        $count = substr_count($content, $oldString);

        if ($count === 0) {
            return $this->error("old_string not found in file. Verify the exact text including whitespace.");
        }

        if (!$replaceAll && $count > 1) {
            return $this->error("old_string found {$count} times — must be unique. Add more surrounding context or set replace_all: true.");
        }

        if ($replaceAll) {
            $newContent = str_replace($oldString, $newString, $content);
        } else {
            // Replace only the first (and only) occurrence
            $pos = strpos($content, $oldString);
            $newContent = substr($content, 0, $pos) . $newString . substr($content, $pos + strlen($oldString));
        }

        $bytes = file_put_contents($path, $newContent);
        if ($bytes === false) {
            return $this->error("Failed to write file: {$path}");
        }

        return $this->success([
            'path' => $path,
            'replacements' => $replaceAll ? $count : 1,
            'bytes_written' => $bytes,
        ]);
    }
}
