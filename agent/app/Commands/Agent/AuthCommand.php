<?php

namespace App\Commands\Agent;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;
use App\Libraries\Auth\OAuthManager;
use App\Libraries\Auth\OAuthCallbackServer;

/**
 * Manage authentication for providers.
 * Supports OAuth login flows and manual token injection.
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

    public function run(array $params)
    {
        $action = $params[0] ?? 'status';
        $provider = $params[1] ?? null;

        switch ($action) {
            case 'status':
                $this->showStatus();
                break;
            case 'login':
                if (!$provider) {
                    CLI::error('Usage: php spark agent:auth login <provider>');
                    CLI::write('Providers with OAuth: chatgpt, claude_api');
                    return;
                }
                $this->oauthLogin($provider);
                break;
            case 'token':
                if (!$provider) {
                    CLI::error('Usage: php spark agent:auth token <provider>');
                    return;
                }
                $this->manualToken($provider);
                break;
            case 'refresh':
                if (!$provider) {
                    CLI::error('Usage: php spark agent:auth refresh <provider>');
                    return;
                }
                $this->refreshToken($provider);
                break;
            case 'revoke':
                if (!$provider) {
                    CLI::error('Usage: php spark agent:auth revoke <provider>');
                    return;
                }
                $this->revokeToken($provider);
                break;
            default:
                CLI::write('Usage:', 'yellow');
                CLI::write('  agent:auth status              Show auth status');
                CLI::write('  agent:auth login <provider>    OAuth login flow');
                CLI::write('  agent:auth token <provider>    Paste a token manually');
                CLI::write('  agent:auth refresh <provider>  Force token refresh');
                CLI::write('  agent:auth revoke <provider>   Remove stored token');
        }
    }

    private function showStatus(): void
    {
        $storage = new FileStorage();
        $config = new ConfigLoader($storage);
        $oauth = new OAuthManager($storage);

        CLI::write('=== Authentication Status ===', 'green');
        CLI::newLine();

        $providersConfig = $config->get('providers', 'providers', []);

        foreach ($providersConfig as $name => $cfg) {
            if (!($cfg['enabled'] ?? false)) continue;

            CLI::write("  {$name}:", 'cyan');

            // Check API key
            $apiKeyEnv = $cfg['api_key_env'] ?? '';
            $hasApiKey = false;
            if (!empty($cfg['api_key'])) {
                $hasApiKey = true;
            } elseif ($apiKeyEnv && getenv($apiKeyEnv)) {
                $hasApiKey = true;
            }

            if ($hasApiKey) {
                CLI::write("    API Key: configured ({$apiKeyEnv})", 'green');
            } else {
                CLI::write("    API Key: not set" . ($apiKeyEnv ? " ({$apiKeyEnv})" : ''), 'light_gray');
            }

            // Check OAuth
            $oauthEnabled = !empty($cfg['oauth']['enabled']);
            if ($oauthEnabled) {
                $tokenInfo = $oauth->getTokenInfo($name);
                if ($tokenInfo) {
                    $status = $tokenInfo['expired'] ? 'expired' : 'valid';
                    $color = $tokenInfo['expired'] ? 'red' : 'green';
                    CLI::write("    OAuth: {$status} ({$tokenInfo['auth_method']}) {$tokenInfo['token_preview']}", $color);
                    if ($tokenInfo['expires_at']) {
                        CLI::write("    Expires: {$tokenInfo['expires_at']}", 'light_gray');
                    }
                    CLI::write("    Refresh token: " . ($tokenInfo['has_refresh_token'] ? 'yes' : 'no'), 'light_gray');
                } else {
                    CLI::write("    OAuth: enabled but no token stored", 'yellow');
                    CLI::write("    Run: php spark agent:auth login {$name}", 'yellow');
                }
            } else {
                // Check if OAuth could be supported
                $type = $cfg['type'] ?? $name;
                if (in_array($type, ['chatgpt', 'claude_api'])) {
                    CLI::write("    OAuth: available (not enabled)", 'light_gray');
                }
            }

            CLI::newLine();
        }
    }

    private function oauthLogin(string $provider): void
    {
        $storage = new FileStorage();
        $config = new ConfigLoader($storage);
        $oauth = new OAuthManager($storage);

        $providersConfig = $config->get('providers', 'providers', []);
        $providerConfig = $providersConfig[$provider] ?? null;

        if (!$providerConfig) {
            CLI::error("Provider not found: {$provider}");
            CLI::write('Available providers: ' . implode(', ', array_keys($providersConfig)));
            return;
        }

        $oauthConfig = $providerConfig['oauth'] ?? [];
        if (empty($oauthConfig['client_id'])) {
            CLI::error("OAuth not configured for {$provider}.");
            CLI::newLine();
            CLI::write('To enable OAuth, add to writable/agent/config/providers.json:', 'yellow');
            CLI::write("  \"{$provider}\": {");
            CLI::write('    "oauth": {');
            CLI::write('      "enabled": true,');
            CLI::write('      "client_id": "your-client-id",');
            CLI::write('      "client_secret": "your-client-secret"');
            CLI::write('    }');
            CLI::write('  }');
            CLI::newLine();

            // Offer manual token entry as alternative
            $manual = CLI::prompt('Would you like to paste a token manually instead?', ['y', 'n']);
            if ($manual === 'y') {
                $this->manualToken($provider);
            }
            return;
        }

        CLI::write("Starting OAuth login for {$provider}...", 'yellow');
        CLI::newLine();

        // Choose flow
        CLI::write('  1) Browser login (Authorization Code + PKCE) - recommended');
        CLI::write('  2) Paste token manually');
        $choice = CLI::prompt('  Select method', '1');

        if ($choice === '2') {
            $this->manualToken($provider);
            return;
        }

        // Authorization Code with PKCE flow
        $pkce = $oauth->generatePKCE();
        $state = bin2hex(random_bytes(16));
        $callbackServer = new OAuthCallbackServer(8484);
        $redirectUri = $callbackServer->getRedirectUri();

        $authUrl = $oauth->buildAuthUrl($provider, $oauthConfig, $pkce, $redirectUri, $state);

        CLI::newLine();
        CLI::write('Open this URL in your browser:', 'green');
        CLI::newLine();
        CLI::write("  {$authUrl}", 'cyan');
        CLI::newLine();

        // Try to open browser automatically
        $this->openBrowser($authUrl);

        CLI::write('Waiting for authorization callback on port 8484...', 'yellow');
        CLI::write('(Press Ctrl+C to cancel)', 'light_gray');

        $callback = $callbackServer->waitForCallback();

        if (!$callback) {
            CLI::error('Timed out waiting for callback.');
            return;
        }

        if (isset($callback['error'])) {
            CLI::error('Authorization failed: ' . ($callback['error_description'] ?? $callback['error']));
            return;
        }

        if (empty($callback['code'])) {
            CLI::error('No authorization code received.');
            return;
        }

        // Verify state
        if (($callback['state'] ?? '') !== $state) {
            CLI::error('State mismatch - possible CSRF attack. Aborting.');
            return;
        }

        CLI::write('Authorization code received. Exchanging for token...', 'yellow');

        $token = $oauth->exchangeCode($provider, $oauthConfig, $callback['code'], $pkce['code_verifier'], $redirectUri);

        if ($token) {
            CLI::write("OAuth login successful for {$provider}!", 'green');
            $info = $oauth->getTokenInfo($provider);
            if ($info) {
                CLI::write("  Token: {$info['token_preview']}", 'light_gray');
                CLI::write("  Refresh token: " . ($info['has_refresh_token'] ? 'yes' : 'no'), 'light_gray');
                if ($info['expires_at']) {
                    CLI::write("  Expires: {$info['expires_at']}", 'light_gray');
                }
            }
        } else {
            CLI::error('Failed to exchange authorization code for token.');
        }
    }

    private function manualToken(string $provider): void
    {
        $storage = new FileStorage();
        $oauth = new OAuthManager($storage);

        CLI::write("Paste token for {$provider}:", 'yellow');
        CLI::write('(The token will be stored in writable/agent/config/oauth/)', 'light_gray');
        CLI::newLine();

        $accessToken = CLI::prompt('  Access token');
        if (empty($accessToken)) {
            CLI::error('No token provided.');
            return;
        }

        $refreshToken = CLI::prompt('  Refresh token (optional, press Enter to skip)');
        $expiresIn = CLI::prompt('  Expires in seconds (optional, press Enter for no expiry)');

        $oauth->storeManualToken(
            $provider,
            $accessToken,
            $refreshToken ?: null,
            $expiresIn ? (int)$expiresIn : null
        );

        CLI::write("Token stored for {$provider}.", 'green');

        // If this provider isn't enabled with OAuth yet, offer to enable it
        $config = new ConfigLoader($storage);
        $providersConfig = $config->get('providers', 'providers', []);
        if (isset($providersConfig[$provider])) {
            if (empty($providersConfig[$provider]['oauth']['enabled'])) {
                $enable = CLI::prompt("Enable OAuth for {$provider} in config?", ['y', 'n']);
                if ($enable === 'y') {
                    $providersConfig[$provider]['oauth'] = $providersConfig[$provider]['oauth'] ?? [];
                    $providersConfig[$provider]['oauth']['enabled'] = true;
                    $storage->writeJson('config/providers.json', ['providers' => $providersConfig]);
                    CLI::write("OAuth enabled for {$provider}.", 'green');
                }
            }
        }
    }

    private function refreshToken(string $provider): void
    {
        $storage = new FileStorage();
        $oauth = new OAuthManager($storage);

        if (!$oauth->hasToken($provider)) {
            CLI::error("No token stored for {$provider}.");
            return;
        }

        CLI::write("Refreshing token for {$provider}...", 'yellow');

        // Force refresh by getting the token (OAuthManager handles refresh internally)
        $token = $oauth->getAccessToken($provider);
        if ($token) {
            CLI::write("Token refreshed successfully.", 'green');
            $info = $oauth->getTokenInfo($provider);
            if ($info && $info['expires_at']) {
                CLI::write("  New expiry: {$info['expires_at']}", 'light_gray');
            }
        } else {
            CLI::error("Token refresh failed. Try logging in again: php spark agent:auth login {$provider}");
        }
    }

    private function revokeToken(string $provider): void
    {
        $oauth = new OAuthManager(new FileStorage());

        if (!$oauth->hasToken($provider)) {
            CLI::write("No token stored for {$provider}.", 'light_gray');
            return;
        }

        $confirm = CLI::prompt("Remove stored token for {$provider}?", ['y', 'n']);
        if ($confirm === 'y') {
            $oauth->revokeToken($provider);
            CLI::write("Token removed for {$provider}.", 'green');
        }
    }

    /**
     * Try to open URL in default browser.
     */
    private function openBrowser(string $url): void
    {
        $os = PHP_OS_FAMILY;
        $cmd = match ($os) {
            'Darwin' => "open " . escapeshellarg($url),
            'Linux' => "xdg-open " . escapeshellarg($url),
            'Windows' => "start " . escapeshellarg($url),
            default => null,
        };

        if ($cmd) {
            @exec($cmd . ' > /dev/null 2>&1 &');
        }
    }
}
