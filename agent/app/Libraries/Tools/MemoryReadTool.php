<?php

namespace App\Libraries\Tools;

use App\Libraries\Memory\MemoryManager;

/**
 * Allows the agent to read from its memory stores.
 *
 * The agent can recall permanent notes, session notes, global notes,
 * or search across all memory by keyword.
 */
class MemoryReadTool extends BaseTool
{
    protected string $name = 'memory_read';
    protected string $description = 'Recall information from memory (permanent notes, session notes, or search)';

    public function getInputSchema(): array
    {
        return [
            'type' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Memory type to read: "permanent", "session", "global", "all", or "search". Default: all.',
            ],
            'query' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Search query to filter notes by content. Only used with type "search".',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Max number of notes to return. Default: 20.',
            ],
        ];
    }

    public function execute(array $args): array
    {
        $type = $args['type'] ?? 'all';
        $query = $args['query'] ?? null;
        $limit = (int)($args['limit'] ?? 20);
        $sessionId = $args['_session_id'] ?? null;

        $memory = new MemoryManager();
        $results = [];

        if ($type === 'search' && $query) {
            $results = $memory->search($query, $limit);
            return $this->success([
                'query' => $query,
                'count' => count($results),
                'notes' => $results,
            ], 'Search complete');
        }

        if (in_array($type, ['permanent', 'all'])) {
            $permanent = $memory->getPermanentNotes();
            if ($type === 'permanent') {
                return $this->success([
                    'count' => count($permanent),
                    'notes' => array_slice($permanent, -$limit),
                ], 'Permanent memory');
            }
            $results['permanent'] = array_slice($permanent, -$limit);
        }

        if (in_array($type, ['session', 'all']) && $sessionId) {
            $sessionNotes = $memory->getSessionNotes($sessionId);
            if ($type === 'session') {
                return $this->success([
                    'count' => count($sessionNotes),
                    'notes' => array_slice($sessionNotes, -$limit),
                ], 'Session memory');
            }
            $results['session'] = array_slice($sessionNotes, -$limit);
        }

        if (in_array($type, ['global', 'all'])) {
            $globalNotes = $memory->getGlobalNotes();
            if ($type === 'global') {
                return $this->success([
                    'count' => count($globalNotes),
                    'notes' => array_slice($globalNotes, -$limit),
                ], 'Global memory');
            }
            $results['global'] = array_slice($globalNotes, -$limit);
        }

        // Summary for 'all'
        $summary = $memory->getGlobalSummary();
        if ($summary) {
            $results['summary'] = $summary;
        }

        return $this->success($results, 'All memory');
    }
}
