<?php

namespace App\Libraries\Tools;

/**
 * Send notifications via configured channels: email (SMTP), Slack, Discord, Telegram, or desktop.
 */
class NotificationSendTool extends BaseTool
{
    protected string $name = 'notification_send';
    protected string $description = 'Send notifications via email (SMTP), Slack webhook, Discord webhook, Telegram bot, or desktop notification';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 15,
            'channels' => [
                'desktop' => ['enabled' => true],
                'slack' => [
                    'enabled' => false,
                    'webhook_url_env' => 'SLACK_WEBHOOK_URL',
                ],
                'discord' => [
                    'enabled' => false,
                    'webhook_url_env' => 'DISCORD_WEBHOOK_URL',
                ],
                'telegram' => [
                    'enabled' => false,
                    'bot_token_env' => 'TELEGRAM_BOT_TOKEN',
                    'chat_id_env' => 'TELEGRAM_CHAT_ID',
                ],
                'email' => [
                    'enabled' => false,
                    'smtp_host' => 'localhost',
                    'smtp_port' => 25,
                    'smtp_user' => '',
                    'smtp_pass_env' => 'SMTP_PASSWORD',
                    'from' => 'phpclaw@localhost',
                ],
            ],
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'channel' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Notification channel: desktop, slack, discord, telegram, email',
            ],
            'message' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Notification message body',
            ],
            'title' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Notification title/subject (default: "PHPClaw")',
            ],
            'to' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Recipient (email address for email channel)',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['channel', 'message'])) return $err;

        $channel = $args['channel'];
        $message = $args['message'];
        $title = $args['title'] ?? 'PHPClaw';

        $channels = $this->config['channels'] ?? [];
        if (!isset($channels[$channel])) {
            return $this->error("Unknown channel: {$channel}. Available: " . implode(', ', array_keys($channels)));
        }

        $channelConfig = $channels[$channel];
        if (!($channelConfig['enabled'] ?? false)) {
            return $this->error("Channel '{$channel}' is not enabled. Configure it in tools.json.");
        }

        return match ($channel) {
            'desktop'  => $this->sendDesktop($title, $message),
            'slack'    => $this->sendSlack($channelConfig, $title, $message),
            'discord'  => $this->sendDiscord($channelConfig, $title, $message),
            'telegram' => $this->sendTelegram($channelConfig, $title, $message),
            'email'    => $this->sendEmail($channelConfig, $title, $message, $args['to'] ?? null),
            default    => $this->error("Unhandled channel: {$channel}"),
        };
    }

    private function sendDesktop(string $title, string $message): array
    {
        $os = PHP_OS_FAMILY;
        $sent = false;

        if ($os === 'Darwin') {
            // macOS
            $script = sprintf(
                'display notification %s with title %s',
                escapeshellarg($message),
                escapeshellarg($title)
            );
            exec('osascript -e ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);
            $sent = $exitCode === 0;
        } elseif ($os === 'Linux') {
            exec(sprintf('notify-send %s %s 2>&1',
                escapeshellarg($title),
                escapeshellarg($message)
            ), $output, $exitCode);
            $sent = $exitCode === 0;
        } elseif ($os === 'Windows') {
            // PowerShell toast notification
            $ps = sprintf(
                '[Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] | Out-Null; ' .
                '$template = [Windows.UI.Notifications.ToastNotificationManager]::GetTemplateContent(0); ' .
                '$text = $template.GetElementsByTagName("text"); $text[0].AppendChild($template.CreateTextNode("%s")); ' .
                '$toast = [Windows.UI.Notifications.ToastNotification]::new($template); ' .
                '[Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier("PHPClaw").Show($toast)',
                addslashes($message)
            );
            exec('powershell -Command ' . escapeshellarg($ps) . ' 2>&1', $output, $exitCode);
            $sent = $exitCode === 0;
        }

        if (!$sent) {
            return $this->error("Desktop notification not supported on this platform: {$os}");
        }

        return $this->success([
            'channel' => 'desktop',
            'title' => $title,
            'message' => $message,
            'platform' => $os,
        ]);
    }

    private function sendSlack(array $config, string $title, string $message): array
    {
        $webhookUrl = getenv($config['webhook_url_env'] ?? 'SLACK_WEBHOOK_URL');
        if (!$webhookUrl) {
            return $this->error("Slack webhook URL not configured. Set {$config['webhook_url_env']} env var.");
        }

        $payload = json_encode([
            'text' => "*{$title}*\n{$message}",
        ]);

        return $this->postWebhook($webhookUrl, $payload, 'slack');
    }

    private function sendDiscord(array $config, string $title, string $message): array
    {
        $webhookUrl = getenv($config['webhook_url_env'] ?? 'DISCORD_WEBHOOK_URL');
        if (!$webhookUrl) {
            return $this->error("Discord webhook URL not configured. Set {$config['webhook_url_env']} env var.");
        }

        $payload = json_encode([
            'embeds' => [[
                'title' => $title,
                'description' => $message,
                'color' => 0x5865F2,
            ]],
        ]);

        return $this->postWebhook($webhookUrl, $payload, 'discord');
    }

    private function sendTelegram(array $config, string $title, string $message): array
    {
        $token = getenv($config['bot_token_env'] ?? 'TELEGRAM_BOT_TOKEN');
        $chatId = getenv($config['chat_id_env'] ?? 'TELEGRAM_CHAT_ID');

        if (!$token || !$chatId) {
            return $this->error("Telegram not configured. Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID env vars.");
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = json_encode([
            'chat_id' => $chatId,
            'text' => "<b>{$title}</b>\n{$message}",
            'parse_mode' => 'HTML',
        ]);

        return $this->postWebhook($url, $payload, 'telegram');
    }

    private function sendEmail(array $config, string $title, string $message, ?string $to): array
    {
        if (!$to) {
            return $this->error("Email requires a 'to' address");
        }

        $from = $config['from'] ?? 'phpclaw@localhost';
        $host = $config['smtp_host'] ?? 'localhost';
        $port = (int)($config['smtp_port'] ?? 25);

        $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        // Use PHP's built-in mail() for simplicity, or SMTP socket for configured servers
        if ($host === 'localhost' && $port === 25) {
            $sent = @mail($to, $title, $message, $headers);
        } else {
            // Basic SMTP via fsockopen
            $sent = $this->smtpSend($config, $to, $title, $message);
        }

        if (!$sent) {
            return $this->error("Failed to send email to {$to}");
        }

        return $this->success([
            'channel' => 'email',
            'to' => $to,
            'subject' => $title,
            'sent' => true,
        ]);
    }

    private function postWebhook(string $url, string $payload, string $channel): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => (int)($this->config['timeout'] ?? 15),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("{$channel} webhook failed: {$error}");
        }

        if ($httpCode >= 400) {
            return $this->error("{$channel} webhook returned HTTP {$httpCode}: {$response}");
        }

        return $this->success([
            'channel' => $channel,
            'http_code' => $httpCode,
            'sent' => true,
        ]);
    }

    private function smtpSend(array $config, string $to, string $subject, string $body): bool
    {
        $host = $config['smtp_host'] ?? 'localhost';
        $port = (int)($config['smtp_port'] ?? 25);
        $user = $config['smtp_user'] ?? '';
        $pass = getenv($config['smtp_pass_env'] ?? 'SMTP_PASSWORD') ?: '';
        $from = $config['from'] ?? 'phpclaw@localhost';

        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) return false;

        $read = fgets($socket);
        fputs($socket, "EHLO phpclaw\r\n"); fgets($socket);

        if ($user && $pass) {
            fputs($socket, "AUTH LOGIN\r\n"); fgets($socket);
            fputs($socket, base64_encode($user) . "\r\n"); fgets($socket);
            fputs($socket, base64_encode($pass) . "\r\n"); fgets($socket);
        }

        fputs($socket, "MAIL FROM:<{$from}>\r\n"); fgets($socket);
        fputs($socket, "RCPT TO:<{$to}>\r\n"); fgets($socket);
        fputs($socket, "DATA\r\n"); fgets($socket);
        fputs($socket, "Subject: {$subject}\r\nFrom: {$from}\r\nTo: {$to}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n.\r\n");
        $response = fgets($socket);
        fputs($socket, "QUIT\r\n");
        fclose($socket);

        return str_starts_with(trim($response), '250');
    }
}
