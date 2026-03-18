<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Session\SessionManager;
use App\Libraries\UI\TerminalUI;

class SessionShowCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:session:show';
    protected $description = 'Show session details and transcript';
    protected $usage = 'agent:session:show <session_id>';

    public function run(array $params)
    {
        $ui = new TerminalUI();
        $id = $params[0] ?? null;

        if (!$id) {
            $ui->error('Usage: php spark agent:session:show <session_id>');
            return;
        }

        $sessions = new SessionManager(new FileStorage());
        $session = $sessions->get($id);

        if (!$session) {
            $ui->error("Session not found: {$id}");
            return;
        }

        $ui->header('Session: ' . $session['name']);
        $ui->newLine();

        $statusColor = match($session['status'] ?? '') {
            'active'   => 'bright_green',
            'archived' => 'gray',
            default    => 'white',
        };

        $ui->keyValue([
            'ID'       => $session['id'],
            'Status'   => $ui->style($session['status'] ?? 'unknown', $statusColor),
            'Created'  => $session['created_at'] ?? '',
            'Messages' => $session['message_count'] ?? 0,
        ]);

        $transcript = $sessions->getTranscript($id);
        if (!empty($transcript)) {
            $ui->newLine();
            $ui->divider('Transcript', 'bright_yellow');
            $ui->newLine();

            foreach ($transcript as $event) {
                $role = $event['role'] ?? 'system';
                $content = $event['content'] ?? '';
                $time = $event['timestamp'] ?? '';

                $roleStyle = match ($role) {
                    'user'      => ['bright_cyan', 'U'],
                    'assistant' => ['bright_green', 'A'],
                    default     => ['gray', 'S'],
                };

                $prefix = $ui->style("[{$time}]", 'gray')
                        . ' ' . $ui->style("[{$roleStyle[1]}]", $roleStyle[0]);
                echo "  {$prefix} " . mb_substr($content, 0, 200) . "\n";
            }
        }
        $ui->newLine();
    }
}
