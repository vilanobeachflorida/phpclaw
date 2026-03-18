<?php

namespace App\Libraries\Tools;

use App\Libraries\Memory\MemoryManager;

/**
 * Allows the agent to explicitly save things to long-term memory.
 *
 * The agent should use this whenever it learns something the user
 * might want it to remember later: preferences, project details,
 * decisions, important facts, corrections, etc.
 *
 * Memory types:
 *   permanent — persists forever, never compacted (user prefs, facts)
 *   session   — tied to the current session
 *   global    — general notes, subject to compaction
 */
class MemoryWriteTool extends BaseTool
{
    protected string $name = 'memory_write';
    protected string $description = 'Save something to long-term memory for future reference';

    public function getInputSchema(): array
    {
        return [
            'content' => [
                'type' => 'string',
                'required' => true,
                'description' => 'What to remember. Be specific and concise.',
            ],
            'type' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Memory type: "permanent" (never forgotten), "session" (this session), or "global" (general). Default: permanent.',
            ],
            'tags' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Comma-separated tags for categorization (e.g. "preference,coding-style")',
            ],
        ];
    }

    public function execute(array $args): array
    {
        $check = $this->requireArgs($args, ['content']);
        if ($check) return $check;

        $content = trim($args['content']);
        $type = $args['type'] ?? 'permanent';
        $tags = isset($args['tags']) ? array_map('trim', explode(',', $args['tags'])) : [];
        $sessionId = $args['_session_id'] ?? null;

        if (empty($content)) {
            return $this->error('Content cannot be empty');
        }

        $memory = new MemoryManager();

        $note = [
            'content' => $content,
            'source' => 'agent',
            'tags' => $tags,
        ];

        switch ($type) {
            case 'permanent':
                $note['permanent'] = true;
                $memory->addPermanentNote($note);
                return $this->success(['type' => 'permanent', 'content' => $content], 'Saved to permanent memory');

            case 'session':
                if (!$sessionId) {
                    return $this->error('No active session for session memory');
                }
                $memory->addSessionNote($sessionId, $note);
                return $this->success(['type' => 'session', 'content' => $content], 'Saved to session memory');

            case 'global':
                $memory->addGlobalNote($note);
                return $this->success(['type' => 'global', 'content' => $content], 'Saved to global memory');

            default:
                return $this->error("Unknown memory type: {$type}. Use permanent, session, or global.");
        }
    }
}
