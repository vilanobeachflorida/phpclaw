<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Session\SessionManager;

class SessionShowCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:session:show';
    protected $description = 'Show session details and transcript';
    protected $usage = 'agent:session:show <session_id>';

    public function run(array $params)
    {
        $id = $params[0] ?? null;
        if (!$id) {
            CLI::error('Usage: php spark agent:session:show <session_id>');
            return;
        }

        $sessions = new SessionManager(new FileStorage());
        $session = $sessions->get($id);

        if (!$session) {
            CLI::error("Session not found: {$id}");
            return;
        }

        CLI::write('=== Session: ' . $session['name'] . ' ===', 'green');
        CLI::write('ID: ' . $session['id']);
        CLI::write('Status: ' . $session['status']);
        CLI::write('Created: ' . $session['created_at']);
        CLI::write('Messages: ' . ($session['message_count'] ?? 0));
        CLI::newLine();

        $transcript = $sessions->getTranscript($id);
        if (!empty($transcript)) {
            CLI::write('--- Transcript ---', 'yellow');
            foreach ($transcript as $event) {
                $role = $event['role'] ?? 'system';
                $type = $event['event_type'] ?? 'message';
                $content = $event['content'] ?? '';
                $time = $event['timestamp'] ?? '';

                $color = match ($role) {
                    'user' => 'cyan',
                    'assistant' => 'white',
                    default => 'dark_gray',
                };

                $prefix = strtoupper(substr($role, 0, 1));
                CLI::write("[{$time}] [{$prefix}] " . mb_substr($content, 0, 200), $color);
            }
        }
    }
}
