<?php

namespace App\Libraries\Auth;

use App\Libraries\Storage\FileStorage;

/**
 * Manages OAuth tokens for providers.
 * Stores tokens in files, handles refresh, supports Authorization Code with PKCE
 * (localhost redirect) and Device Authorization Grant flows.
 *
 * Token storage: writable/agent/config/oauth/<provider>.json
 */
class OAuthManager
{
    private FileStorage $storage;

    /**
     * Built-in OAuth client IDs for PHPClaw.
     *
     * Overridable via environment variables or providers.json.
     * If no client ID is configured anywhere, the user will be
     * prompted during setup.
     */
    private const BUILTIN_CLIENTS = [
        'chatgpt' => [
            'client_id_env' => 'PHPCLAW_OPENAI_CLIENT_ID',
            'client_secret_env' => 'PHPCLAW_OPENAI_CLIENT_SECRET',
        ],
    ];

    /** Known OAuth endpoints for supported providers. */
    private static array $providerEndpoints = [
        'chatgpt' => [
            'authorize_url' => 'https://auth.openai.com/oauth/authorize',
            'token_url' => 'https://auth.openai.com/oauth/token',
            'device_url' => null,
            'scopes' => 'openid profile email',
            'audience' => 'https://api.openai.com/v1',
        ],
    ];

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
        $this->storage->ensureDir($this->storage->path('config', 'oauth'));
    }

    /**
     * Resolve the OAuth client config for a provider.
     *
     * Priority:
     *   1. Config from providers.json (oauth.client_id)
     *   2. Environment variable override (PHPCLAW_*_CLIENT_ID)
     *   3. Built-in PHPClaw client ID
     *
     * Returns ['client_id' => '...', 'client_secret' => '...'] or null.
     */
    public function resolveOAuthConfig(string $provider, array $configOauth = []): ?array
    {
        $builtin = self::BUILTIN_CLIENTS[$provider] ?? null;

        // 1. Explicit config
        $clientId = $configOauth['client_id'] ?? null;
        $clientSecret = $configOauth['client_secret'] ?? null;

        // 2. Environment variable override
        if (empty($clientId) && $builtin) {
            $envId = getenv($builtin['client_id_env'] ?? '');
            if ($envId) $clientId = $envId;
        }
        if (empty($clientSecret) && $builtin) {
            $envSecret = getenv($builtin['client_secret_env'] ?? '');
            if ($envSecret) $clientSecret = $envSecret;
        }

        if (empty($clientId)) return null;

        return [
            'enabled' => true,
            'client_id' => $clientId,
            'client_secret' => $clientSecret ?? '',
        ];
    }

    /**
     * Check if a provider supports browser-based OAuth.
     * Only ChatGPT/OpenAI does. Claude uses setup-token instead.
     */
    public function hasOAuthSupport(string $provider): bool
    {
        return isset(self::$providerEndpoints[$provider]);
    }

    /**
     * Check if a provider supports setup-token auth (Claude).
     */
    public function hasSetupTokenSupport(string $provider): bool
    {
        return in_array($provider, ['claude_api', 'claude'], true);
    }

    /**
     * Get a valid access token for a provider. Returns null if no token or expired without refresh.
     */
    public function getAccessToken(string $provider): ?string
    {
        $token = $this->loadToken($provider);
        if (!$token) {
            return null;
        }

        // Check expiration
        if ($this->isExpired($token)) {
            // Try refresh
            if (!empty($token['refresh_token'])) {
                $refreshed = $this->refreshToken($provider, $token);
                if ($refreshed) {
                    return $refreshed['access_token'];
                }
            }
            return null;
        }

        return $token['access_token'] ?? null;
    }

    /**
     * Check if a provider has a stored token (expired or not).
     */
    public function hasToken(string $provider): bool
    {
        return $this->loadToken($provider) !== null;
    }

    /**
     * Check if a provider's token is valid (exists and not expired).
     */
    public function isValid(string $provider): bool
    {
        $token = $this->loadToken($provider);
        if (!$token) return false;
        if ($this->isExpired($token)) {
            // Try refresh silently
            if (!empty($token['refresh_token'])) {
                return $this->refreshToken($provider, $token) !== null;
            }
            return false;
        }
        return true;
    }

    /**
     * Store a token for a provider.
     */
    public function storeToken(string $provider, array $tokenData): bool
    {
        $token = [
            'provider' => $provider,
            'access_token' => $tokenData['access_token'] ?? null,
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'expires_in' => $tokenData['expires_in'] ?? null,
            'expires_at' => null,
            'scope' => $tokenData['scope'] ?? null,
            'id_token' => $tokenData['id_token'] ?? null,
            'stored_at' => date('c'),
            'auth_method' => $tokenData['auth_method'] ?? 'manual',
        ];

        // Calculate absolute expiration time
        if ($token['expires_in']) {
            $token['expires_at'] = time() + (int)$token['expires_in'];
        }

        return $this->storage->writeJson("config/oauth/{$provider}.json", $token);
    }

    /**
     * Store a manually-provided token (paste from browser, external tool, etc.).
     */
    public function storeManualToken(string $provider, string $accessToken, string $refreshToken = null, int $expiresIn = null): bool
    {
        return $this->storeToken($provider, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
            'auth_method' => 'manual',
        ]);
    }

    /**
     * Store a setup-token from Claude Code CLI.
     *
     * Claude doesn't use browser OAuth. Instead:
     *   1. User runs `claude setup-token` in their terminal
     *   2. Gets a token string
     *   3. Pastes it here
     *
     * The token is stored the same way as OAuth tokens so providers
     * can resolve it transparently.
     */
    public function storeSetupToken(string $provider, string $token): bool
    {
        return $this->storeToken($provider, [
            'access_token' => $token,
            'auth_method' => 'setup_token',
        ]);
    }

    /**
     * Remove stored token for a provider.
     */
    public function revokeToken(string $provider): bool
    {
        $path = $this->storage->path('config', 'oauth', "{$provider}.json");
        if (file_exists($path)) {
            return unlink($path);
        }
        return false;
    }

    /**
     * Get token info for display (masks sensitive values).
     */
    public function getTokenInfo(string $provider): ?array
    {
        $token = $this->loadToken($provider);
        if (!$token) return null;

        return [
            'provider' => $provider,
            'has_access_token' => !empty($token['access_token']),
            'has_refresh_token' => !empty($token['refresh_token']),
            'token_preview' => $this->maskToken($token['access_token'] ?? ''),
            'token_type' => $token['token_type'] ?? 'Bearer',
            'expires_at' => $token['expires_at'] ? date('c', $token['expires_at']) : null,
            'expired' => $this->isExpired($token),
            'stored_at' => $token['stored_at'] ?? null,
            'auth_method' => $token['auth_method'] ?? 'unknown',
        ];
    }

    /**
     * List all stored OAuth tokens.
     */
    public function listTokens(): array
    {
        $dir = $this->storage->path('config', 'oauth');
        $tokens = [];
        $files = glob($dir . '/*.json');
        foreach ($files as $file) {
            $provider = basename($file, '.json');
            $info = $this->getTokenInfo($provider);
            if ($info) {
                $tokens[] = $info;
            }
        }
        return $tokens;
    }

    // ---- Authorization Code with PKCE (localhost redirect) ----

    /**
     * Generate PKCE challenge for Authorization Code flow.
     */
    public function generatePKCE(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            'code_verifier' => $verifier,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
    }

    /**
     * Build the authorization URL for the Authorization Code flow.
     */
    public function buildAuthUrl(string $provider, array $oauthConfig, array $pkce, string $redirectUri, string $state): string
    {
        $endpoints = $this->getEndpoints($provider, $oauthConfig);

        $params = [
            'response_type' => 'code',
            'client_id' => $oauthConfig['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $pkce['code_challenge'],
            'code_challenge_method' => $pkce['code_challenge_method'],
        ];

        if (!empty($endpoints['scopes'])) {
            $params['scope'] = $endpoints['scopes'];
        }
        if (!empty($endpoints['audience'])) {
            $params['audience'] = $endpoints['audience'];
        }

        return $endpoints['authorize_url'] . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens.
     */
    public function exchangeCode(string $provider, array $oauthConfig, string $code, string $codeVerifier, string $redirectUri): ?array
    {
        $endpoints = $this->getEndpoints($provider, $oauthConfig);

        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => $oauthConfig['client_id'],
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];

        if (!empty($oauthConfig['client_secret'])) {
            $payload['client_secret'] = $oauthConfig['client_secret'];
        }

        $result = $this->httpPost($endpoints['token_url'], $payload);
        if ($result && !empty($result['access_token'])) {
            $result['auth_method'] = 'authorization_code';
            $this->storeToken($provider, $result);
            return $result;
        }

        return null;
    }

    /**
     * Extract authorization code and state from a pasted redirect URL.
     *
     * Users paste the full URL from their browser's address bar after authorization:
     *   http://localhost:8484/callback?code=abc123&state=xyz789
     *
     * Returns ['code' => '...', 'state' => '...'] or null on failure.
     */
    public function extractCodeFromUrl(string $url): ?array
    {
        $parsed = parse_url(trim($url));
        if (!$parsed || empty($parsed['query'])) {
            return null;
        }

        parse_str($parsed['query'], $params);

        if (!empty($params['error'])) {
            return ['error' => $params['error'], 'error_description' => $params['error_description'] ?? null];
        }

        if (empty($params['code'])) {
            return null;
        }

        return [
            'code' => $params['code'],
            'state' => $params['state'] ?? null,
        ];
    }

    /**
     * Run a complete browser-based OAuth login flow.
     *
     * This is the all-in-one method used by the setup wizard and auth command.
     * It handles:
     *   1. PKCE generation
     *   2. Auth URL construction
     *   3. Callback server OR manual URL paste
     *   4. Code exchange
     *
     * Returns a result array:
     *   ['success' => true, 'token_info' => [...]]
     *   ['success' => false, 'error' => '...']
     *
     * The $callbacks array controls UI interaction:
     *   'showUrl'      => fn(string $authUrl)           — display the URL to the user
     *   'onWaiting'    => fn()                           — show "waiting for callback"
     *   'promptPaste'  => fn(): ?string                  — ask user to paste redirect URL (fallback)
     *   'onExchanging' => fn()                           — show "exchanging code"
     */
    public function browserLogin(string $provider, array $oauthConfig = [], array $callbacks = []): array
    {
        // Resolve OAuth config — use built-in client IDs if not provided
        if (empty($oauthConfig['client_id'])) {
            $resolved = $this->resolveOAuthConfig($provider, $oauthConfig);
            if (!$resolved) {
                return ['success' => false, 'error' => "No OAuth client ID available for {$provider}"];
            }
            $oauthConfig = array_merge($oauthConfig, $resolved);
        }

        $pkce = $this->generatePKCE();
        $state = bin2hex(random_bytes(16));

        // Determine redirect URI and whether we'll use callback server
        $callbackPort = 8484;
        $redirectUri = "http://localhost:{$callbackPort}/callback";

        $authUrl = $this->buildAuthUrl($provider, $oauthConfig, $pkce, $redirectUri, $state);

        // Show URL to user
        if (isset($callbacks['showUrl'])) {
            ($callbacks['showUrl'])($authUrl);
        }

        // Try to open browser
        $this->tryOpenBrowser($authUrl);

        // Try callback server first
        $callback = null;
        $callbackServer = new OAuthCallbackServer($callbackPort, 120);

        if (isset($callbacks['onWaiting'])) {
            ($callbacks['onWaiting'])();
        }

        $callback = $callbackServer->waitForCallback();

        // If callback server failed/timed out, offer paste fallback
        if (!$callback && isset($callbacks['promptPaste'])) {
            $pastedUrl = ($callbacks['promptPaste'])();
            if ($pastedUrl) {
                $callback = $this->extractCodeFromUrl($pastedUrl);
            }
        }

        if (!$callback) {
            return ['success' => false, 'error' => 'No authorization response received'];
        }

        if (isset($callback['error'])) {
            return ['success' => false, 'error' => $callback['error_description'] ?? $callback['error']];
        }

        if (empty($callback['code'])) {
            return ['success' => false, 'error' => 'No authorization code received'];
        }

        // Verify state
        if (($callback['state'] ?? '') !== $state) {
            return ['success' => false, 'error' => 'State mismatch — possible CSRF attack'];
        }

        // Exchange code for tokens
        if (isset($callbacks['onExchanging'])) {
            ($callbacks['onExchanging'])();
        }

        $token = $this->exchangeCode($provider, $oauthConfig, $callback['code'], $pkce['code_verifier'], $redirectUri);

        if (!$token) {
            return ['success' => false, 'error' => 'Failed to exchange authorization code for token'];
        }

        return [
            'success' => true,
            'token_info' => $this->getTokenInfo($provider),
        ];
    }

    /**
     * Try to open a URL in the default browser. Best-effort, no error on failure.
     */
    private function tryOpenBrowser(string $url): void
    {
        $cmd = match (PHP_OS_FAMILY) {
            'Darwin'  => "open " . escapeshellarg($url),
            'Linux'   => "xdg-open " . escapeshellarg($url),
            'Windows' => "start " . escapeshellarg($url),
            default   => null,
        };
        if ($cmd) {
            @exec($cmd . ' > /dev/null 2>&1 &');
        }
    }

    // ---- Device Authorization Grant ----

    /**
     * Start device authorization flow.
     */
    public function startDeviceAuth(string $provider, array $oauthConfig): ?array
    {
        $endpoints = $this->getEndpoints($provider, $oauthConfig);
        $deviceUrl = $endpoints['device_url'] ?? null;

        if (!$deviceUrl) {
            return null; // Provider doesn't support device flow
        }

        $payload = [
            'client_id' => $oauthConfig['client_id'],
        ];

        if (!empty($endpoints['scopes'])) {
            $payload['scope'] = $endpoints['scopes'];
        }
        if (!empty($endpoints['audience'])) {
            $payload['audience'] = $endpoints['audience'];
        }

        return $this->httpPost($deviceUrl, $payload);
    }

    /**
     * Poll for device authorization completion.
     */
    public function pollDeviceAuth(string $provider, array $oauthConfig, string $deviceCode): ?array
    {
        $endpoints = $this->getEndpoints($provider, $oauthConfig);

        $payload = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $oauthConfig['client_id'],
            'device_code' => $deviceCode,
        ];

        $result = $this->httpPost($endpoints['token_url'], $payload);
        if ($result && !empty($result['access_token'])) {
            $result['auth_method'] = 'device_code';
            $this->storeToken($provider, $result);
            return $result;
        }

        return $result; // May contain 'error' => 'authorization_pending'
    }

    // ---- Internal helpers ----

    private function loadToken(string $provider): ?array
    {
        return $this->storage->readJson("config/oauth/{$provider}.json");
    }

    private function isExpired(array $token): bool
    {
        if (empty($token['expires_at'])) {
            return false; // No expiration = assume valid
        }
        return time() >= $token['expires_at'];
    }

    /**
     * Refresh an expired access token using the refresh token.
     */
    private function refreshToken(string $provider, array $token): ?array
    {
        // Load provider OAuth config
        $storage = $this->storage;
        $providersConfig = $storage->readJson('config/providers.json') ?? [];
        $providerConfig = $providersConfig['providers'][$provider] ?? [];
        $oauthConfig = $providerConfig['oauth'] ?? [];

        if (empty($oauthConfig['client_id'])) {
            return null; // Can't refresh without client_id
        }

        $endpoints = $this->getEndpoints($provider, $oauthConfig);

        $payload = [
            'grant_type' => 'refresh_token',
            'client_id' => $oauthConfig['client_id'],
            'refresh_token' => $token['refresh_token'],
        ];

        if (!empty($oauthConfig['client_secret'])) {
            $payload['client_secret'] = $oauthConfig['client_secret'];
        }

        $result = $this->httpPost($endpoints['token_url'], $payload);
        if ($result && !empty($result['access_token'])) {
            // Preserve refresh token if not returned
            if (empty($result['refresh_token'])) {
                $result['refresh_token'] = $token['refresh_token'];
            }
            $result['auth_method'] = $token['auth_method'] ?? 'refresh';
            $this->storeToken($provider, $result);
            return $result;
        }

        return null;
    }

    /**
     * Get OAuth endpoints for a provider, merging defaults with config overrides.
     */
    private function getEndpoints(string $provider, array $oauthConfig): array
    {
        $defaults = self::$providerEndpoints[$provider] ?? [
            'authorize_url' => '',
            'token_url' => '',
            'device_url' => null,
            'scopes' => '',
            'audience' => '',
        ];

        return [
            'authorize_url' => $oauthConfig['authorize_url'] ?? $defaults['authorize_url'],
            'token_url' => $oauthConfig['token_url'] ?? $defaults['token_url'],
            'device_url' => $oauthConfig['device_url'] ?? $defaults['device_url'],
            'scopes' => $oauthConfig['scopes'] ?? $defaults['scopes'],
            'audience' => $oauthConfig['audience'] ?? $defaults['audience'],
        ];
    }

    private function maskToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return '****';
        }
        return substr($token, 0, 4) . '...' . substr($token, -4);
    }

    private function httpPost(string $url, array $data): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return null;
        }

        return json_decode($response, true);
    }
}
