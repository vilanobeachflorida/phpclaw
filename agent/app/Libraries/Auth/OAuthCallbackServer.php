<?php

namespace App\Libraries\Auth;

/**
 * Minimal localhost HTTP server for OAuth callback.
 * Listens on a local port, receives the authorization code redirect,
 * extracts the code and state, then shuts down.
 *
 * Used by the Authorization Code with PKCE flow for CLI-based OAuth.
 */
class OAuthCallbackServer
{
    private int $port;
    private int $timeout;

    public function __construct(int $port = 8484, int $timeout = 120)
    {
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getRedirectUri(): string
    {
        return "http://localhost:{$this->port}/callback";
    }

    /**
     * Listen for the OAuth callback. Blocks until callback received or timeout.
     * Returns ['code' => '...', 'state' => '...'] on success, null on timeout/error.
     */
    public function waitForCallback(): ?array
    {
        $server = @stream_socket_server("tcp://127.0.0.1:{$this->port}", $errno, $errstr);
        if (!$server) {
            return null;
        }

        stream_set_timeout($server, $this->timeout);

        $startTime = time();
        $result = null;

        while (time() - $startTime < $this->timeout) {
            $client = @stream_socket_accept($server, $this->timeout - (time() - $startTime));
            if (!$client) {
                break;
            }

            $request = fread($client, 8192);
            if (!$request) {
                fclose($client);
                continue;
            }

            // Parse the HTTP request line
            if (preg_match('/GET\s+\/callback\?(.+?)\s+HTTP/', $request, $matches)) {
                parse_str($matches[1], $params);

                if (!empty($params['code'])) {
                    $result = [
                        'code' => $params['code'],
                        'state' => $params['state'] ?? null,
                    ];

                    // Send success response to browser
                    $body = '<html><body style="font-family:sans-serif;text-align:center;padding:60px">'
                        . '<h2>Authorization successful!</h2>'
                        . '<p>You can close this window and return to your terminal.</p>'
                        . '</body></html>';

                    $response = "HTTP/1.1 200 OK\r\n"
                        . "Content-Type: text/html\r\n"
                        . "Content-Length: " . strlen($body) . "\r\n"
                        . "Connection: close\r\n"
                        . "\r\n"
                        . $body;

                    fwrite($client, $response);
                    fclose($client);
                    break;
                }

                if (!empty($params['error'])) {
                    $result = [
                        'error' => $params['error'],
                        'error_description' => $params['error_description'] ?? null,
                    ];

                    $body = '<html><body style="font-family:sans-serif;text-align:center;padding:60px">'
                        . '<h2>Authorization failed</h2>'
                        . '<p>' . htmlspecialchars($params['error_description'] ?? $params['error']) . '</p>'
                        . '</body></html>';

                    $response = "HTTP/1.1 400 Bad Request\r\n"
                        . "Content-Type: text/html\r\n"
                        . "Content-Length: " . strlen($body) . "\r\n"
                        . "Connection: close\r\n"
                        . "\r\n"
                        . $body;

                    fwrite($client, $response);
                    fclose($client);
                    break;
                }
            }

            fclose($client);
        }

        fclose($server);
        return $result;
    }
}
