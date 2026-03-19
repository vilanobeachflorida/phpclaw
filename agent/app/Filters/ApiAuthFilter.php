<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Storage\FileStorage;
use App\Libraries\Storage\ConfigLoader;

/**
 * Bearer token authentication filter for the API.
 * Reads the token from writable/agent/config/api.json.
 */
class ApiAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // OPTIONS requests pass through (CORS preflight)
        if ($request->getMethod() === 'OPTIONS') {
            return;
        }

        $storage = new FileStorage();
        $config  = new ConfigLoader($storage);
        $apiConf = $config->load('api');

        // If API is disabled, reject all requests
        if (!($apiConf['enabled'] ?? true)) {
            return service('response')
                ->setStatusCode(503)
                ->setJSON(['error' => 'API is disabled']);
        }

        $token = $apiConf['token'] ?? null;

        // If no token is configured, reject — require explicit setup
        if (empty($token)) {
            return service('response')
                ->setStatusCode(503)
                ->setJSON([
                    'error'   => 'API token not configured',
                    'message' => 'Run: php spark agent:api:token to generate one, or set "token" in writable/agent/config/api.json',
                ]);
        }

        // Extract Bearer token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => 'Missing or invalid Authorization header. Use: Bearer <token>']);
        }

        $provided = substr($authHeader, 7);
        if (!hash_equals($token, $provided)) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => 'Invalid API token']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Add CORS headers to every API response
        $storage = new FileStorage();
        $config  = new ConfigLoader($storage);
        $apiConf = $config->load('api');
        $cors    = $apiConf['cors'] ?? [];

        $origin  = implode(', ', $cors['allowed_origins'] ?? ['*']);
        $methods = implode(', ', $cors['allowed_methods'] ?? ['GET', 'POST', 'OPTIONS']);
        $headers = implode(', ', $cors['allowed_headers'] ?? ['Authorization', 'Content-Type']);

        $response->setHeader('Access-Control-Allow-Origin', $origin);
        $response->setHeader('Access-Control-Allow-Methods', $methods);
        $response->setHeader('Access-Control-Allow-Headers', $headers);

        return $response;
    }
}
