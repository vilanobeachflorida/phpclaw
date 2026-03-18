<?php

namespace App\Libraries\Memory;

use App\Libraries\Storage\FileStorage;

/**
 * Manages layered file-based memory system.
 *
 * Memory types:
 *   Permanent — agent-written notes that persist forever (user prefs, facts, corrections)
 *   Session   — per-session context from conversation
 *   Global    — cross-session notes subject to compaction
 *   Module    — per-module patterns and notes
 *   Task      — per-task context
 *
 * Layers:
 *   Raw Logs (transcript.ndjson) → Notes (notes.ndjson) → Summaries (summary.md) → Compacted (compacted/*.json)
 *
 * Raw logs are never destroyed. Compaction creates artifacts alongside them.
 */
class MemoryManager
{
    private FileStorage $storage;

    /** Auto-compact when global notes exceed this count. */
    private int $autoCompactThreshold = 200;

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
    }

    // ── Permanent Memory ────────────────────────────────────────────
    // Agent-written notes that persist forever and are never compacted.
    // These are things the agent explicitly decides to remember.

    /**
     * Add a permanent memory note. These are never compacted or removed.
     */
    public function addPermanentNote(array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        $note['permanent'] = true;
        $this->storage->ensureDir($this->storage->path('memory', 'permanent'));
        return $this->storage->appendNdjson('memory/permanent/notes.ndjson', $note);
    }

    /**
     * Read all permanent memory notes.
     */
    public function getPermanentNotes(): array
    {
        return $this->storage->readNdjson('memory/permanent/notes.ndjson');
    }

    // ── Global Memory ───────────────────────────────────────────────
    // Cross-session notes. Subject to compaction.

    public function addGlobalNote(array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        $result = $this->storage->appendNdjson('memory/global/notes.ndjson', $note);

        // Auto-compact if threshold reached
        $count = $this->storage->countNdjsonLines('memory/global/notes.ndjson');
        if ($count >= $this->autoCompactThreshold) {
            $this->compactGlobalMemory();
        }

        return $result;
    }

    public function getGlobalNotes(): array
    {
        return $this->storage->readNdjson('memory/global/notes.ndjson');
    }

    public function getGlobalSummary(): ?string
    {
        return $this->storage->readText('memory/global/summary.md');
    }

    public function writeGlobalSummary(string $summary): bool
    {
        return $this->storage->writeText('memory/global/summary.md', $summary);
    }

    // ── Session Memory ──────────────────────────────────────────────

    public function addSessionNote(string $sessionId, array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        $this->storage->ensureDir($this->storage->path('memory', 'sessions', $sessionId));
        return $this->storage->appendNdjson("memory/sessions/{$sessionId}/notes.ndjson", $note);
    }

    public function getSessionNotes(string $sessionId): array
    {
        return $this->storage->readNdjson("memory/sessions/{$sessionId}/notes.ndjson");
    }

    public function writeSessionSummary(string $sessionId, string $summary): bool
    {
        return $this->storage->writeText("memory/sessions/{$sessionId}/summary.md", $summary);
    }

    public function getSessionSummary(string $sessionId): ?string
    {
        return $this->storage->readText("memory/sessions/{$sessionId}/summary.md");
    }

    // ── Module Memory ───────────────────────────────────────────────

    public function addModuleNote(string $moduleName, array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        $this->storage->ensureDir($this->storage->path('memory', 'modules', $moduleName));
        return $this->storage->appendNdjson("memory/modules/{$moduleName}/notes.ndjson", $note);
    }

    // ── Task Memory ─────────────────────────────────────────────────

    public function addTaskNote(string $taskId, array $note): bool
    {
        $note['timestamp'] = $note['timestamp'] ?? date('c');
        $note['id'] = $note['id'] ?? bin2hex(random_bytes(8));
        $this->storage->ensureDir($this->storage->path('memory', 'tasks', $taskId));
        return $this->storage->appendNdjson("memory/tasks/{$taskId}/notes.ndjson", $note);
    }

    // ── Search ──────────────────────────────────────────────────────

    /**
     * Search across all memory types for notes matching a query.
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = strtolower($query);
        $results = [];

        // Search permanent notes
        foreach ($this->getPermanentNotes() as $note) {
            if ($this->noteMatchesQuery($note, $query)) {
                $note['_source'] = 'permanent';
                $results[] = $note;
            }
        }

        // Search global notes
        foreach ($this->getGlobalNotes() as $note) {
            if ($this->noteMatchesQuery($note, $query)) {
                $note['_source'] = 'global';
                $results[] = $note;
            }
        }

        // Sort by timestamp descending (newest first)
        usort($results, function ($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });

        return array_slice($results, 0, $limit);
    }

    private function noteMatchesQuery(array $note, string $query): bool
    {
        $content = strtolower($note['content'] ?? $note['text'] ?? '');
        $tags = strtolower(implode(' ', $note['tags'] ?? []));
        return str_contains($content, $query) || str_contains($tags, $query);
    }

    // ── Context Building ────────────────────────────────────────────
    // Methods for building memory context to inject into prompts.

    /**
     * Build a memory context block for injection into the system prompt.
     * Combines permanent notes, global summary, and recent session context.
     *
     * Returns a formatted string ready to embed in the prompt, or empty string.
     */
    public function buildPromptContext(string $sessionId = null, int $maxTokenEstimate = 2000): string
    {
        $sections = [];

        // Permanent memory — always included
        $permanent = $this->getPermanentNotes();
        if (!empty($permanent)) {
            $lines = [];
            foreach ($permanent as $note) {
                $content = $note['content'] ?? $note['text'] ?? '';
                $tags = !empty($note['tags']) ? ' [' . implode(', ', $note['tags']) . ']' : '';
                $lines[] = "- {$content}{$tags}";
            }
            $sections[] = "### Permanent Memory\nThese are things you've explicitly saved. Always reference these.\n\n" . implode("\n", $lines);
        }

        // Global summary — include if it exists
        $globalSummary = $this->getGlobalSummary();
        if ($globalSummary) {
            $sections[] = "### Knowledge Summary\n" . mb_substr($globalSummary, 0, 1000);
        }

        // Recent session notes — last N from current session
        if ($sessionId) {
            $sessionNotes = $this->getSessionNotes($sessionId);
            $recent = array_slice($sessionNotes, -15);
            if (!empty($recent)) {
                $lines = [];
                foreach ($recent as $note) {
                    $content = $note['content'] ?? $note['text'] ?? '';
                    $lines[] = "- " . mb_substr($content, 0, 200);
                }
                $sections[] = "### Recent Session Context\n" . implode("\n", $lines);
            }
        }

        // Recent global notes (cross-session) — last 10
        $globalNotes = $this->getGlobalNotes();
        $recentGlobal = array_slice($globalNotes, -10);
        if (!empty($recentGlobal) && !$globalSummary) {
            $lines = [];
            foreach ($recentGlobal as $note) {
                $content = $note['content'] ?? $note['text'] ?? '';
                $lines[] = "- " . mb_substr($content, 0, 200);
            }
            $sections[] = "### Recent Activity\n" . implode("\n", $lines);
        }

        if (empty($sections)) return '';

        $context = "## Memory\nYou have access to memory from previous interactions. Use this context but don't repeat it to the user unless asked.\n\n" . implode("\n\n", $sections);

        // Rough trim if too long (4 chars ≈ 1 token)
        if (strlen($context) > $maxTokenEstimate * 4) {
            $context = mb_substr($context, 0, $maxTokenEstimate * 4) . "\n... (memory truncated)";
        }

        return $context;
    }

    // ── Transcript Ingestion ────────────────────────────────────────

    /**
     * Ingest key information from a conversation turn into memory.
     * Called after each agent turn to extract memory-worthy content.
     *
     * Only ingests assistant messages that are substantive (not just "OK" etc).
     */
    public function ingestTurn(string $sessionId, string $userMessage, string $assistantResponse, array $toolsUsed = []): int
    {
        $notesAdded = 0;

        // Store a compact session note about what happened
        if (strlen($assistantResponse) > 50) {
            $summary = mb_substr($assistantResponse, 0, 300);
            $this->addSessionNote($sessionId, [
                'content' => $summary,
                'source' => 'transcript',
                'event_type' => 'turn_summary',
                'tools_used' => array_map(fn($t) => $t['tool'] ?? $t, $toolsUsed),
            ]);
            $notesAdded++;
        }

        // Also add a global note for cross-session context
        // but only for substantial interactions
        if (strlen($assistantResponse) > 100 && !empty($toolsUsed)) {
            $userSummary = mb_substr($userMessage, 0, 100);
            $responseSummary = mb_substr($assistantResponse, 0, 200);
            $tools = array_map(fn($t) => $t['tool'] ?? $t, $toolsUsed);
            $toolStr = implode(', ', $tools);

            $this->addGlobalNote([
                'content' => "User asked: {$userSummary}. Used tools: {$toolStr}. Result: {$responseSummary}",
                'source' => 'transcript',
                'session_id' => $sessionId,
            ]);
            $notesAdded++;
        }

        return $notesAdded;
    }

    /**
     * Legacy ingest method for bulk transcript events.
     */
    public function ingestTranscript(string $sessionId, array $events): int
    {
        $notesAdded = 0;
        foreach ($events as $event) {
            $type = $event['event_type'] ?? '';
            $content = $event['content'] ?? '';

            if (in_array($type, ['assistant_message', 'tool_result', 'task_update']) && strlen($content) > 20) {
                $note = [
                    'source' => 'transcript',
                    'session_id' => $sessionId,
                    'event_type' => $type,
                    'content' => mb_substr($content, 0, 500),
                ];
                $this->addSessionNote($sessionId, $note);
                $this->addGlobalNote($note);
                $notesAdded++;
            }
        }
        return $notesAdded;
    }

    // ── Compaction ──────────────────────────────────────────────────

    public function compactGlobalMemory(): array
    {
        $notes = $this->getGlobalNotes();
        if (empty($notes)) {
            return ['compacted' => false, 'reason' => 'No notes to compact'];
        }

        $compactedId = date('Ymd-His');
        $compactedPath = "memory/global/compacted/{$compactedId}.json";

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

        // Build a summary
        $summaryLines = [];
        foreach ($notes as $note) {
            $content = $note['content'] ?? $note['text'] ?? '';
            if ($content) {
                $summaryLines[] = '- ' . mb_substr($content, 0, 200);
            }
        }
        $summary = "# Memory Summary\n\nCompacted: {$compactedId}\nNotes: " . count($notes) . "\n\n" . implode("\n", array_slice($summaryLines, -50));

        $this->storage->writeText("memory/global/summaries/{$compactedId}.md", $summary);
        $this->writeGlobalSummary($summary);

        // Clear the notes file (they're archived in compacted/)
        $this->storage->writeText('memory/global/notes.ndjson', '');

        // Update index
        $index = $this->storage->readJson('memory/global/index.json') ?? [
            'total_notes' => 0, 'total_summaries' => 0, 'last_compaction' => null, 'compaction_count' => 0
        ];
        $index['total_notes'] = 0;
        $index['total_summaries'] = ($index['total_summaries'] ?? 0) + 1;
        $index['last_compaction'] = date('c');
        $index['compaction_count'] = ($index['compaction_count'] ?? 0) + 1;
        $index['notes_compacted'] = ($index['notes_compacted'] ?? 0) + count($notes);
        $this->storage->writeJson('memory/global/index.json', $index);

        return [
            'compacted' => true,
            'artifact' => $compactedPath,
            'note_count' => count($notes),
            'compacted_id' => $compactedId,
        ];
    }

    // ── Stats ───────────────────────────────────────────────────────

    public function getStats(): array
    {
        $index = $this->storage->readJson('memory/global/index.json') ?? [];
        $globalNoteCount = $this->storage->countNdjsonLines('memory/global/notes.ndjson');
        $permanentNoteCount = $this->storage->countNdjsonLines('memory/permanent/notes.ndjson');

        return [
            'permanent_notes' => $permanentNoteCount,
            'global_notes' => $globalNoteCount,
            'total_summaries' => $index['total_summaries'] ?? 0,
            'last_compaction' => $index['last_compaction'] ?? null,
            'compaction_count' => $index['compaction_count'] ?? 0,
            'notes_compacted' => $index['notes_compacted'] ?? 0,
        ];
    }
}
