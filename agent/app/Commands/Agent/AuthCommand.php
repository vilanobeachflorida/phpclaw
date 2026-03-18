<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Auth\OAuthManager;
use App\Libraries\UI\TerminalUI;

/**
 * Manage authentication for providers.
 * Supports OAuth browser login with paste-URL fallback, and manual token injection.
 *
 * Usage:
 *   php spark agent:auth status                  Show auth status for all providers
 *   php spark agent:auth login <provider>         Start OAuth login flow
 *   php spark agent:auth token <provider>         Paste a token manually
 *   php spark agent:auth refresh <provider>       Force token refresh
 *   php spark agent:auth revoke <provider>        Remove stored token
 */
class AuthCommand extends BaseCommand
{
    protected $group = 'agent';
    protected $name = 'agent:auth';
    protected $description = 'Manage provider authentication (OAuth, API keys, tokens)';
    protected $usage = 'agent:auth <action> [provider]';

    private TerminalUI $ui;

    public function run(array $params)
    {
        $this->ui = new TerminalUI();
        $action = $params[0] ?? 'status';
        $provider = $params[1] ?? null;

        switch ($action) {
            case 'status':
                $this->showStatus();
                break;
            case 'login':
                if (!$provider) {
                    $this->ui->error('Usage: php spark agent:auth login <provider>');
                    $this->ui->dim('Providers with OAuth: chatgpt, claude_api');
                    return;
                }
                $this->oauthLogin($provider);
                break;
            case 'token':
                if (!$provider) {
                    $this->ui->error('Usage: php spark agent:auth token <provider>');
                    return;
                }
                $this->manualToken($provider);
                break;
            case 'refresh':
                if (!$provider) {
                    $this->ui->error('Usage: php spark agent:auth refresh <provider>');
                    return;
                }
                $this->refreshToken($provider);
                break;
            case 'revoke':
                if (!$provider) {
                    $this->ui->error('Usage: php spark agent:auth revoke <provider>');
                    return;
                }
                $this->revokeToken($provider);
                break;
            default:
                $this->ui->header('Authentication');
                $this->ui->slashHelp([
                    'status'              => 'Show auth status for all providers',
                    'login <provider>'    => 'Start OAuth browser login flow',
                    'token <provider>'    => 'Paste a token manually',
                    'refresh <provider>'  => 'Force token refresh',
                    'revoke <provider>'   => 'Remove stored token',
                ]);
        }
    }

    private function showStatus(): void
    {
        $storage = new FileStorage();
        $config = new ConfigLoader($storage);
        $oauth = new OAuthManager($storage);

        $this->ui->header('Authentication Status');

        $providersConfig = $config->get('providers', 'providers', []);
        $rows = [];

        foreach ($providersConfig as $name => $cfg) {
            if (!($cfg['enabled'] ?? false)) continue;

            // API key status
            $apiKeyEnv = $cfg['api_key_env'] ?? '';
            $hasApiKey = !empty($cfg['api_key']) || ($apiKeyEnv && getenv($apiKeyEnv));
            $apiKeyStatus = $hasApiKey
                ? $this->ui->style('configured', 'bright_green')
                : $this->ui->style('not set', 'gray');

            // OAuth status
            $oauthEnabled = !empty($cfg['oauth']['enabled']);
            $oauthStatus = '';
            if ($oauthEnabled) {
                $tokenInfo = $oauth->getTokenInfo($name);
                if ($tokenInfo) {
                    if ($tokenInfo['expired']) {
                        $oauthStatus = $this->ui->style('expired', 'bright_red');
                    } else {
                        $oauthStatus = $this->ui->style('valid', 'bright_green')
                            . $this->ui->style(" {$tokenInfo['token_preview']}", 'gray');
                    }
                } else {
                    $oauthStatus = $this->ui->style('no token', 'bright_yellow');
                }
            } else {
                $type = $cfg['type'] ?? $name;
                if (in_array($type, ['chatgpt', 'claude_api'])) {
                    $oauthStatus = $this->ui->style('available', 'gray');
                } else {
                    $oauthStatus = $this->ui->style('n/a', 'gray');
                }
            }

            $rows[] = [
                $this->ui->style($name, 'bright_cyan'),
                $apiKeyStatus,
                $oauthStatus,
            ];
        }

        $this->ui->newLine();
        if (!empty($rows)) {
            $this->ui->table(['Provider', 'API Key', 'OAuth'], $rows, 'blue');
        } else {
            $this->ui->dim('No providers enabled. Run: php spark agent:setup');
        }
        $this->ui->newLine();
    }

    private function oauthLogin(string $provider): void
    {
        $storage = new FileStorage();
        $config = new ConfigLoader($storage);
        $oauth = new OAuthManager($storage);

        $providersConfig = $config->get('providers', 'providers', []);
        $providerConfig = $providersConfig[$provider] ?? null;

        if (!$providerConfig) {
            $this->ui->error("Provider not found: {$provider}");
            $this->ui->dim('Available: ' . implode(', ', array_keys($providersConfig)));
            return;
        }

        $oauthConfig = $providerConfig['oauth'] ?? [];
        if (empty($oauthConfig['client_id'])) {
            $this->ui->error("OAuth not configured for {$provider}.");
            $this->ui->newLine();
            $this->ui->infoBox(
                "To enable OAuth, add to providers.json:",
                "  \"{$provider}\": { \"oauth\": {",
                "    \"enabled\": true,",
                "    \"client_id\": \"your-client-id\"",
                "  }}",
            );

            $this->ui->newLine();
            if ($this->ui->confirm('Paste a token manually instead?', false)) {
                $this->manualToken($provider);
            }
            return;
        }

        // Choose flow
        $choice = $this->ui->menu("Login to {$provider}", [
            ['label' => 'Browser login',  'description' => 'Authorization Code + PKCE (recommended)'],
            ['label' => 'Paste token',    'description' => 'Enter an access token manually'],
        ]);

        if ($choice === null) return;

        if ($choice === 1) {
            $this->manualToken($provider);
            return;
        }

        // Run browser flow using the shared browserLogin method
        $this->ui->newLine();
        $this->ui->divider('Browser Login', 'bright_cyan');
        $this->ui->newLine();

        $result = $oauth->browserLogin($provider, $oauthConfig, [
            'showUrl' => function (string $authUrl) {
                $this->ui->info('Open this URL in your browser to authorize:');
                $this->ui->newLine();
                $this->ui->box([$authUrl], 'bright_cyan');
                $this->ui->newLine();
                $this->ui->dim('(Attempting to open your browser automatically...)');
            },

            'onWaiting' => function () {
                $this->ui->newLine();
                $this->ui->inline($this->ui->style('  ◆', 'bright_magenta'));
                $this->ui->write(' Waiting for authorization...', 'gray');
                $this->ui->dim('  Complete the login in your browser.');
                $this->ui->dim('  If nothing happens, you can paste the redirect URL below.');
                $this->ui->newLine();
            },

            'promptPaste' => function (): ?string {
                $this->ui->newLine();
                $this->ui->warn('Callback server timed out.');
                $this->ui->newLine();
                $this->ui->info('After authorizing, copy the URL from your browser\'s address bar.');
                $this->ui->dim('It looks like: http://localhost:8484/callback?code=abc123&state=xyz...');
                $this->ui->newLine();

                $url = $this->ui->prompt('Paste redirect URL (or Enter to cancel)');
                if (!$url) return null;
                return $url;
            },

            'onExchanging' => function () {
                $this->ui->inline($this->ui->style('  ◆', 'bright_magenta'));
                $this->ui->write(' Exchanging code for token...', 'gray');
            },
        ]);

        $this->ui->newLine();

        if ($result['success'] ?? false) {
            $info = $result['token_info'] ?? [];
            $this->ui->successBox(
                "Logged in to {$provider}!",
                '',
                'Token: ' . ($info['token_preview'] ?? '****'),
                'Refresh token: ' . (($info['has_refresh_token'] ?? false) ? 'yes' : 'no'),
                ($info['expires_at'] ?? null) ? ('Expires: ' . $info['expires_at']) : 'No expiration',
            );
        } else {
            $this->ui->errorBox(
                'Login failed: ' . ($result['error'] ?? 'Unknown error'),
            );
        }
    }

    private function manualToken(string $provider): void
    {
        $storage = new FileStorage();
        $oauth = new OAuthManager($storage);

        $this->ui->newLine();
        $this->ui->divider("Manual Token for {$provider}", 'bright_cyan');
        $this->ui->dim('Token will be stored in writable/agent/config/oauth/');
        $this->ui->newLine();

        $accessToken = $this->ui->prompt('Access token', '', true);
        if (empty($accessToken)) {
            $this->ui->warn('No token provided');
            return;
        }

        $refreshToken = $this->ui->prompt('Refresh token (optional)', '');
        $expiresIn = $this->ui->prompt('Expires in seconds (optional)', '');

        $oauth->storeManualToken(
            $provider,
            $accessToken,
            $refreshToken ?: null,
            $expiresIn ? (int)$expiresIn : null
        );

        $this->ui->newLine();
        $this->ui->success("Token stored for {$provider}");

        // Enable OAuth if not already
        $config = new ConfigLoader($storage);
        $providersConfig = $config->get('providers', 'providers', []);
        if (isset($providersConfig[$provider]) && empty($providersConfig[$provider]['oauth']['enabled'])) {
            if ($this->ui->confirm("Enable OAuth for {$provider} in config?", true)) {
                $providersConfig[$provider]['oauth'] = $providersConfig[$provider]['oauth'] ?? [];
                $providersConfig[$provider]['oauth']['enabled'] = true;
                $storage->writeJson('config/providers.json', ['providers' => $providersConfig]);
                $this->ui->success("OAuth enabled for {$provider}");
            }
        }
    }

    private function refreshToken(string $provider): void
    {
        $storage = new FileStorage();
        $oauth = new OAuthManager($storage);

        if (!$oauth->hasToken($provider)) {
            $this->ui->error("No token stored for {$provider}");
            return;
        }

        $token = $this->ui->spinner("Refreshing token for {$provider}", function() use ($oauth, $provider) {
            return $oauth->getAccessToken($provider);
        });

        if ($token) {
            $info = $oauth->getTokenInfo($provider);
            $this->ui->success('Token refreshed');
            if ($info && $info['expires_at']) {
                $this->ui->dim("New expiry: {$info['expires_at']}");
            }
        } else {
            $this->ui->error("Refresh failed. Try: php spark agent:auth login {$provider}");
        }
    }

    private function revokeToken(string $provider): void
    {
        $oauth = new OAuthManager(new FileStorage());

        if (!$oauth->hasToken($provider)) {
            $this->ui->dim("No token stored for {$provider}");
            return;
        }

        if ($this->ui->confirm("Remove stored token for {$provider}?", false)) {
            $oauth->revokeToken($provider);
            $this->ui->success("Token removed for {$provider}");
        }
    }
}
