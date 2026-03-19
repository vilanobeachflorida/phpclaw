<?php

namespace App\Libraries\Tools;

/**
 * Shared curl session manager with cookie persistence and browser spoofing.
 *
 * Provides a realistic browser fingerprint so that websites treat requests
 * as coming from a normal user rather than a bot. Manages a cookie jar
 * per named session so multi-step flows (login → navigate) work seamlessly.
 */
class CurlSession
{
    private static array $cookieJars = [];
    private static string $cookieDir = '';

    /** Realistic Chrome on macOS user-agent */
    public const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** Standard browser headers */
    public const BROWSER_HEADERS = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Sec-Ch-Ua: "Chromium";v="131", "Not_A Brand";v="24"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "macOS"',
    ];

    /**
     * Get the cookie jar file path for a given session name.
     * Creates the cookies directory if needed.
     */
    public static function getCookieJar(string $session = 'default'): string
    {
        if (isset(self::$cookieJars[$session])) {
            return self::$cookieJars[$session];
        }

        if (self::$cookieDir === '') {
            self::$cookieDir = WRITEPATH . 'agent/cookies';
        }

        if (!is_dir(self::$cookieDir)) {
            mkdir(self::$cookieDir, 0700, true);
        }

        $path = self::$cookieDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $session) . '.txt';
        self::$cookieJars[$session] = $path;

        return $path;
    }

    /**
     * Normalize a URL so www/non-www variants stay consistent within a session.
     * Checks the cookie jar to see which domain variant has cookies set,
     * then rewrites the URL to match so cookies are always sent.
     */
    public static function normalizeUrl(string $url, string $session = 'default'): string
    {
        $cookieJar = self::getCookieJar($session);

        // If the cookie jar exists, check which domain variant has cookies
        if (file_exists($cookieJar) && filesize($cookieJar) > 0) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host) return $url;

            // Determine the bare domain (without www.)
            $isWwwUrl = str_starts_with($host, 'www.');
            $bare = $isWwwUrl ? substr($host, 4) : $host;
            $www = 'www.' . $bare;

            // Read cookie jar and check for domain entries
            // Netscape cookie format: domain \t ... (domain starts each line)
            $lines = file($cookieJar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $hasWwwCookie = false;
            $hasBareCookie = false;

            foreach ($lines as $line) {
                if (str_starts_with($line, '#')) continue; // skip comments
                $parts = explode("\t", $line);
                if (count($parts) < 7) continue;
                $cookieDomain = ltrim($parts[0], '.');

                if ($cookieDomain === $www) {
                    $hasWwwCookie = true;
                } elseif ($cookieDomain === $bare) {
                    $hasBareCookie = true;
                }
            }

            if ($hasWwwCookie && !$isWwwUrl) {
                // Cookies are on www, but URL is bare → add www
                $url = str_replace('://' . $bare, '://' . $www, $url);
            } elseif ($hasBareCookie && !$hasWwwCookie && $isWwwUrl) {
                // Cookies are on bare only, but URL has www → strip www
                $url = str_replace('://' . $www, '://' . $bare, $url);
            }
        }

        return $url;
    }

    /**
     * Apply standard browser options to a curl handle.
     * Includes cookie jar, user-agent, and browser headers.
     */
    public static function applyBrowserOptions($ch, string $session = 'default', array $extraHeaders = []): void
    {
        $cookieJar = self::getCookieJar($session);

        $headers = self::BROWSER_HEADERS;
        foreach ($extraHeaders as $header) {
            // Override matching headers instead of duplicating
            $name = strtolower(trim(explode(':', $header, 2)[0]));
            $headers = array_filter($headers, function ($h) use ($name) {
                return strtolower(trim(explode(':', $h, 2)[0])) !== $name;
            });
            $headers[] = $header;
        }

        curl_setopt_array($ch, [
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => array_values($headers),
            CURLOPT_COOKIEFILE     => $cookieJar,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_ENCODING       => '',           // handle gzip/br automatically
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TCP_KEEPALIVE  => 1,
        ]);
    }

    /**
     * Derive the session name from a URL's domain.
     * Always uses the domain as the session key, regardless of what the
     * caller passes. This ensures all requests to the same site share
     * cookies even if the LLM inconsistently names sessions.
     *
     * The explicit session is only used if the URL has no parseable host.
     */
    public static function defaultSessionForUrl(string $url, ?string $explicitSession): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return $explicitSession ?? 'default';
        }

        // Strip www. and use the base domain as session name
        $host = preg_replace('/^www\./', '', $host);
        // Sanitize for filename
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $host);
    }

    /**
     * Clear cookies for a session.
     */
    public static function clearSession(string $session = 'default'): void
    {
        $jar = self::getCookieJar($session);
        if (file_exists($jar)) {
            unlink($jar);
        }
        unset(self::$cookieJars[$session]);
    }

    /**
     * List active sessions.
     */
    public static function listSessions(): array
    {
        if (self::$cookieDir === '' || !is_dir(self::$cookieDir)) {
            return [];
        }

        $sessions = [];
        foreach (glob(self::$cookieDir . '/*.txt') as $file) {
            $sessions[] = basename($file, '.txt');
        }
        return $sessions;
    }
}
