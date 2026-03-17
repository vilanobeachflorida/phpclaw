<?php

namespace App\Libraries\Session;

use App\Libraries\Storage\FileStorage;

/**
 * Manages chat sessions: create, list, resume, archive.
 * Each session is a directory with session.json, transcript.ndjson, etc.
 */
class SessionManager
{
    private FileStorage $storage;
    private ?string $activeSessionId = null;
    private ?array $activeSession = null;

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
    }

    public function create(string $name = null): array
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $name = $name ?? 'session-' . $id;

        $session = [
            'id' => $id,
            'name' => $name,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'status' => 'active',
            'provider' => null,
            'model' => null,
            'module' => null,
            'role' => null,
            'message_count' => 0,
            'metadata' => [],
        ];

        $this->storage->writeJson("sessions/{$id}/session.json", $session);

        // Update index
        $index = $this->storage->readJson('sessions/index.json') ?? ['sessions' => [], 'active_session' => null];
        $index['sessions'][] = ['id' => $id, 'name' => $name, 'created_at' => $session['created_at'], 'status' => 'active'];
        $index['active_session'] = $id;
        $this->storage->writeJson('sessions/index.json', $index);

        $this->activeSessionId = $id;
        $this->activeSession = $session;

        return $session;
    }

    public function list(): array
    {
        $index = $this->storage->readJson('sessions/index.json');
        return $index['sessions'] ?? [];
    }

    public function get(string $id): ?array
    {
        return $this->storage->readJson("sessions/{$id}/session.json");
    }

    public function resume(string $id): ?array
    {
        $session = $this->get($id);
        if (!$session) return null;

        $this->activeSessionId = $id;
        $this->activeSession = $session;

        // Update index
        $index = $this->storage->readJson('sessions/index.json') ?? ['sessions' => [], 'active_session' => null];
        $index['active_session'] = $id;
        $this->storage->writeJson('sessions/index.json', $index);

        return $session;
    }

    public function getActiveId(): ?string
    {
        if ($this->activeSessionId) return $this->activeSessionId;

        $index = $this->storage->readJson('sessions/index.json');
        return $index['active_session'] ?? null;
    }

    public function appendTranscript(string $sessionId, array $event): bool
    {
        $event['timestamp'] = $event['timestamp'] ?? date('c');
        $event['message_id'] = $event['message_id'] ?? bin2hex(random_bytes(8));

        $result = $this->storage->appendNdjson("sessions/{$sessionId}/transcript.ndjson", $event);

        // Update message count
        $session = $this->get($sessionId);
        if ($session) {
            $session['message_count'] = ($session['message_count'] ?? 0) + 1;
            $session['updated_at'] = date('c');
            $this->storage->writeJson("sessions/{$sessionId}/session.json", $session);
        }

        return $result;
    }

    public function getTranscript(string $sessionId): array
    {
        return $this->storage->readNdjson("sessions/{$sessionId}/transcript.ndjson");
    }

    public function appendToolEvent(string $sessionId, array $event): bool
    {
        $event['timestamp'] = $event['timestamp'] ?? date('c');
        return $this->storage->appendNdjson("sessions/{$sessionId}/tool-events.ndjson", $event);
    }

    public function archive(string $sessionId): bool
    {
        $session = $this->get($sessionId);
        if (!$session) return false;

        $session['status'] = 'archived';
        $session['updated_at'] = date('c');
        $this->storage->writeJson("sessions/{$sessionId}/session.json", $session);

        // Update index
        $index = $this->storage->readJson('sessions/index.json') ?? ['sessions' => []];
        foreach ($index['sessions'] as &$s) {
            if ($s['id'] === $sessionId) {
                $s['status'] = 'archived';
                break;
            }
        }
        if (($index['active_session'] ?? null) === $sessionId) {
            $index['active_session'] = null;
        }
        $this->storage->writeJson('sessions/index.json', $index);

        return true;
    }
}
