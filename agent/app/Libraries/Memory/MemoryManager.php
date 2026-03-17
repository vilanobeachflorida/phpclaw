<?php

namespace App\Libraries\Memory;

use App\Libraries\Storage\FileStorage;

/**
 * Manages file-based memory: ingestion, notes, summaries, compaction.
 * Memory is layered: raw logs -> notes -> summaries -> compacted artifacts.
 * Raw logs are never destroyed by compaction.
 */
class MemoryManager
{
    private FileStorage $storage;

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
    }

    /**
     * Add a memory note to global memory.
     */
    public function addGlobalNote(array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        return $this->storage->appendNdjson('memory/global/notes.ndjson', $note);
    }

    /**
     * Add a memory note for a specific session.
     */
    public function addSessionNote(string $sessionId, array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        $this->storage->ensureDir($this->storage->path('memory', 'sessions', $sessionId));
        return $this->storage->appendNdjson("memory/sessions/{$sessionId}/notes.ndjson", $note);
    }

    /**
     * Add a memory note for a specific module.
     */
    public function addModuleNote(string $moduleName, array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        $this->storage->ensureDir($this->storage->path('memory', 'modules', $moduleName));
        return $this->storage->appendNdjson("memory/modules/{$moduleName}/notes.ndjson", $note);
    }

    /**
     * Add a memory note for a specific task.
     */
    public function addTaskNote(string $taskId, array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        $this->storage->ensureDir($this->storage->path('memory', 'tasks', $taskId));
        return $this->storage->appendNdjson("memory/tasks/{$taskId}/notes.ndjson", $note);
    }

    /**
     * Read all global notes.
     */
    public function getGlobalNotes(): array
    {
        return $this->storage->readNdjson('memory/global/notes.ndjson');
    }

    /**
     * Read session notes.
     */
    public function getSessionNotes(string $sessionId): array
    {
        return $this->storage->readNdjson("memory/sessions/{$sessionId}/notes.ndjson");
    }

    /**
     * Get global summary.
     */
    public function getGlobalSummary(): ?string
    {
        return $this->storage->readText('memory/global/summary.md');
    }

    /**
     * Write a summary for global memory.
     */
    public function writeGlobalSummary(string $summary): bool
    {
        return $this->storage->writeText('memory/global/summary.md', $summary);
    }

    /**
     * Write a summary for session memory.
     */
    public function writeSessionSummary(string $sessionId, string $summary): bool
    {
        return $this->storage->writeText("memory/sessions/{$sessionId}/summary.md", $summary);
    }

    /**
     * Compact global memory notes.
     * Creates a compacted artifact and updates the index.
     * Original notes.ndjson is preserved - compacted artifact is stored separately.
     */
    public function compactGlobalMemory(): array
    {
        $notes = $this->getGlobalNotes();
        if (empty($notes)) {
            return ['compacted' => false, 'reason' => 'No notes to compact'];
        }

        $compactedId = date('Ymd-His');
        $compactedPath = "memory/global/compacted/{$compactedId}.json";

        // Build compacted artifact
        $artifact = [
            'id' => $compactedId,
            'created_at' => date('c'),
            'source' => 'memory/global/notes.ndjson',
            'note_count' => count($notes),
            'first_note_time' => $notes[0]['timestamp'] ?? null,
            'last_note_time' => end($notes)['timestamp'] ?? null,
            'notes' => $notes,
        ];

        $this->storage->writeJson($compactedPath, $artifact);

        // Build a summary from notes
        $summaryLines = [];
        foreach ($notes as $note) {
            $content = $note['content'] ?? $note['text'] ?? '';
            if ($content) {
                $summaryLines[] = '- ' . $content;
            }
        }
        $summary = "# Memory Summary\n\nCompacted: {$compactedId}\nNotes: " . count($notes) . "\n\n" . implode("\n", $summaryLines);
        $this->storage->writeText("memory/global/summaries/{$compactedId}.md", $summary);

        // Update index
        $index = $this->storage->readJson('memory/global/index.json') ?? [
            'total_notes' => 0, 'total_summaries' => 0, 'last_compaction' => null, 'compaction_count' => 0
        ];
        $index['total_notes'] = count($notes);
        $index['total_summaries'] = ($index['total_summaries'] ?? 0) + 1;
        $index['last_compaction'] = date('c');
        $index['compaction_count'] = ($index['compaction_count'] ?? 0) + 1;
        $this->storage->writeJson('memory/global/index.json', $index);

        return [
            'compacted' => true,
            'artifact' => $compactedPath,
            'note_count' => count($notes),
            'compacted_id' => $compactedId,
        ];
    }

    /**
     * Ingest transcript events into memory notes.
     * Extracts useful information from conversation events.
     */
    public function ingestTranscript(string $sessionId, array $events): int
    {
        $notesAdded = 0;
        foreach ($events as $event) {
            $type = $event['event_type'] ?? '';
            $content = $event['content'] ?? '';

            // Extract memory-worthy content
            if (in_array($type, ['assistant_message', 'tool_result', 'task_update']) && strlen($content) > 20) {
                $note = [
                    'source' => 'transcript',
                    'session_id' => $sessionId,
                    'event_type' => $type,
                    'content' => mb_substr($content, 0, 500),
                    'metadata' => [
                        'original_timestamp' => $event['timestamp'] ?? null,
                        'provider' => $event['provider'] ?? null,
                        'model' => $event['model'] ?? null,
                    ],
                ];
                $this->addSessionNote($sessionId, $note);
                $this->addGlobalNote($note);
                $notesAdded++;
            }
        }
        return $notesAdded;
    }

    /**
     * Get memory stats.
     */
    public function getStats(): array
    {
        $index = $this->storage->readJson('memory/global/index.json') ?? [];
        $noteCount = $this->storage->countNdjsonLines('memory/global/notes.ndjson');

        return [
            'global_notes' => $noteCount,
            'total_summaries' => $index['total_summaries'] ?? 0,
            'last_compaction' => $index['last_compaction'] ?? null,
            'compaction_count' => $index['compaction_count'] ?? 0,
        ];
    }
}
