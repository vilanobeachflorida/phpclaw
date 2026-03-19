# PHPClaw REST API

PHPClaw includes a built-in REST API that lets you interact with the agent over HTTP. You can send messages, maintain conversation sessions, and query system status — all from any language, framework, or tool that can make HTTP requests.

The API runs its own dedicated HTTP server alongside the CLI, so you can use both at the same time.

## Table of Contents

- [Quick Start](#quick-start)
- [Server Management](#server-management)
- [Authentication](#authentication)
- [Configuration](#configuration)
- [Endpoints](#endpoints)
  - [POST /api/chat](#post-apichat)
  - [GET /api/sessions](#get-apisessions)
  - [GET /api/sessions/:id](#get-apisessionsid)
  - [POST /api/sessions/:id/archive](#post-apisessionsidarchive)
  - [GET /api/status](#get-apistatus)
  - [GET /api/docs](#get-apidocs)
- [Session Flow](#session-flow)
- [Error Handling](#error-handling)
- [CORS](#cors)
- [Examples](#examples)
  - [cURL](#curl)
  - [Python](#python)
  - [JavaScript / Node.js](#javascript--nodejs)
  - [PHP](#php)

---

## Quick Start

```bash
# 1. Generate an API token
php spark agent:api:token

# 2. Start the API server
php spark agent:api:serve

# 3. Open the interactive docs
#    http://localhost:8081/api/docs

# 4. Send your first message
curl -X POST http://localhost:8081/api/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "Hello! What can you do?"}'
```

---

## Server Management

The API has its own built-in HTTP server that you start and stop independently.

### Starting the server

```bash
# Start with default settings (0.0.0.0:8081)
php spark agent:api:serve

# Custom host and port
php spark agent:api:serve --host 127.0.0.1 --port 9000
```

The server uses PHP's built-in development server. For production, place a reverse proxy (Nginx, Caddy, etc.) in front of it.

### Enabling / disabling

The server has an `enabled` flag in config. When disabled, the `agent:api:serve` command refuses to start.

```bash
# Disable the API server
php spark agent:api:serve --disable

# Re-enable and start
php spark agent:api:serve --enable
```

Or edit `writable/agent/config/api.json` directly:

```json
{
  "server": {
    "enabled": false
  }
}
```

### Disabling the API entirely

Set the top-level `enabled` flag to `false` to reject all API requests (returns 503), even if the server is running via `php spark serve` or another web server:

```json
{
  "enabled": false
}
```

### Token management

```bash
# Generate a token (first time)
php spark agent:api:token

# Show the current token
php spark agent:api:token

# Regenerate (invalidates the old token)
php spark agent:api:token --regenerate
```

If you start `agent:api:serve` without a token, one is generated automatically.

---

## Authentication

All API endpoints except `/api/docs` require a Bearer token in the `Authorization` header:

```
Authorization: Bearer <your-token>
```

The token is a 64-character hex string stored in `writable/agent/config/api.json`. Use `php spark agent:api:token` to generate or view it.

### Auth errors

| Status | Meaning |
|--------|---------|
| `401` | Missing or malformed `Authorization` header |
| `403` | Token present but invalid |
| `503` | API disabled or token not configured |

---

## Configuration

All API settings live in `writable/agent/config/api.json`:

```json
{
  "enabled": true,
  "token": "your-64-char-hex-token",
  "server": {
    "enabled": true,
    "host": "0.0.0.0",
    "port": 8081
  },
  "rate_limit": {
    "requests_per_minute": 30,
    "enabled": false
  },
  "cors": {
    "allowed_origins": ["*"],
    "allowed_methods": ["GET", "POST", "OPTIONS"],
    "allowed_headers": ["Authorization", "Content-Type", "Accept"]
  },
  "defaults": {
    "role": "reasoning",
    "module": "reasoning",
    "max_history": 100
  }
}
```

| Key | Description |
|-----|-------------|
| `enabled` | Master switch — `false` rejects all requests with 503 |
| `token` | Bearer auth token (generate with `php spark agent:api:token`) |
| `server.enabled` | Whether `agent:api:serve` is allowed to start |
| `server.host` | Bind address (`0.0.0.0` = all interfaces, `127.0.0.1` = local only) |
| `server.port` | Port for the dedicated API server (default: `8081`) |
| `rate_limit.enabled` | Enable per-minute rate limiting (not enforced yet — reserved) |
| `rate_limit.requests_per_minute` | Max requests per minute when rate limiting is active |
| `cors.allowed_origins` | Origins permitted for CORS (`["*"]` = any) |
| `cors.allowed_methods` | HTTP methods allowed in CORS preflight |
| `cors.allowed_headers` | Headers allowed in CORS preflight |
| `defaults.role` | Default model role if not specified in request |
| `defaults.module` | Default module if not specified in request |
| `defaults.max_history` | Max messages loaded from transcript per session |

---

## Endpoints

### POST /api/chat

Send a message to the agent and get a response. This is the primary endpoint.

**Request body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message` | string | Yes | The user message to send |
| `session_id` | string | No | Session ID to continue a conversation. Omit to start a new session. |
| `role` | string | No | Override the default role (e.g. `reasoning`, `coding`) |
| `module` | string | No | Override the default module |

**Response** (JSON):

| Field | Type | Description |
|-------|------|-------------|
| `session_id` | string | The session ID (use this to continue the conversation) |
| `response` | string | The agent's text response |
| `usage` | object\|null | Token usage: `input_tokens`, `output_tokens`, `cost`, `tool_calls`, `elapsed_ms` |
| `tools_used` | array | List of tool names the agent invoked during this turn |

**Example:**

```bash
curl -X POST http://localhost:8081/api/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "What files are in the current directory?"}'
```

```json
{
  "session_id": "20260318-143022-a1b2c3d4",
  "response": "Here are the files in the current directory:\n- composer.json\n- spark\n- app/\n- writable/",
  "usage": {
    "input_tokens": 847,
    "output_tokens": 234,
    "cost": 0.004,
    "tool_calls": 1,
    "elapsed_ms": 1200
  },
  "tools_used": ["dir_list"]
}
```

**Notes:**
- The agent runs its full tool loop (file reads, shell commands, web fetches, etc.) before returning.
- Long-running tool chains may take time. Consider appropriate HTTP timeouts.
- The response includes the full agent output after all tool execution is complete.

---

### GET /api/sessions

List all chat sessions.

**Response:**

```json
{
  "sessions": [
    {
      "id": "20260318-143022-a1b2c3d4",
      "name": "api-20260318-143022",
      "created_at": "2026-03-18T14:30:22+00:00",
      "status": "active"
    },
    {
      "id": "20260318-091500-f8e7d6c5",
      "name": "api-20260318-091500",
      "created_at": "2026-03-18T09:15:00+00:00",
      "status": "archived"
    }
  ],
  "count": 2
}
```

---

### GET /api/sessions/:id

Get session metadata and the full message history.

**URL parameters:**

| Parameter | Description |
|-----------|-------------|
| `:id` | The session ID |

**Response:**

```json
{
  "session": {
    "id": "20260318-143022-a1b2c3d4",
    "name": "api-20260318-143022",
    "status": "active",
    "message_count": 6,
    "created_at": "2026-03-18T14:30:22+00:00",
    "updated_at": "2026-03-18T14:35:10+00:00",
    "provider": null,
    "model": null,
    "module": null,
    "role": null,
    "metadata": {}
  },
  "messages": [
    {
      "role": "user",
      "content": "Hello!",
      "timestamp": "2026-03-18T14:30:25+00:00"
    },
    {
      "role": "assistant",
      "content": "Hi! How can I help you today?",
      "timestamp": "2026-03-18T14:30:28+00:00"
    }
  ]
}
```

The `messages` array contains only user and assistant messages (tool calls and system events are filtered out for readability).

---

### POST /api/sessions/:id/archive

Archive (close) a session. Archived sessions are retained in storage but marked inactive.

**URL parameters:**

| Parameter | Description |
|-----------|-------------|
| `:id` | The session ID to archive |

**Response:**

```json
{
  "status": "archived",
  "session_id": "20260318-143022-a1b2c3d4"
}
```

Returns `404` if the session doesn't exist.

---

### GET /api/status

Health check endpoint. Returns system info, active providers, available tools, and default settings.

**Response:**

```json
{
  "status": "ok",
  "version": "0.1.0",
  "providers": [
    { "name": "lmstudio", "description": "LM Studio local inference" },
    { "name": "ollama", "description": "Ollama local model runner" }
  ],
  "tools": [
    "file_read", "file_write", "file_append", "dir_list",
    "mkdir", "move_file", "delete_file", "grep_search",
    "shell_exec", "http_get", "browser_fetch", "browser_text",
    "system_info", "memory_write", "memory_read"
  ],
  "defaults": {
    "role": "reasoning",
    "module": "reasoning"
  }
}
```

---

### GET /api/docs

Interactive HTML documentation page with a built-in "Try It" panel for testing the chat endpoint live in your browser. **This endpoint does not require authentication.**

---

## Session Flow

Sessions let you maintain conversation context across multiple HTTP requests, just like the CLI chat.

```
┌──────────────┐       POST /api/chat           ┌──────────────┐
│   Client     │  ──  {"message": "Hi"}  ──────▶ │   PHPClaw    │
│              │                                  │   API        │
│              │  ◀── {"session_id": "abc", ...}  │              │
│              │                                  │              │
│              │       POST /api/chat             │              │
│              │  ── {"message": "...",   ──────▶ │  (resumes    │
│              │       "session_id": "abc"}        │   session)   │
│              │                                  │              │
│              │  ◀── {"session_id": "abc", ...}  │              │
└──────────────┘                                  └──────────────┘
```

1. **Start** — Send a message without `session_id`. The API creates a new session and returns its ID.
2. **Continue** — Include the `session_id` in subsequent requests. The agent loads the full conversation history.
3. **Review** — Use `GET /api/sessions/:id` to fetch the full message log.
4. **Close** — Use `POST /api/sessions/:id/archive` when the conversation is done.

Sessions are shared between CLI and API — a session started in the API can be viewed with `php spark agent:session:show <id>`, and vice versa.

---

## Error Handling

All errors return JSON with an `error` field:

```json
{
  "error": "Description of what went wrong"
}
```

| HTTP Status | When |
|-------------|------|
| `400` | Bad request (missing `message`, invalid JSON) |
| `401` | Missing or malformed `Authorization` header |
| `403` | Invalid API token |
| `404` | Session not found |
| `503` | API disabled or token not configured |

---

## CORS

The API sets CORS headers on all responses, configured in `api.json`:

```json
{
  "cors": {
    "allowed_origins": ["*"],
    "allowed_methods": ["GET", "POST", "OPTIONS"],
    "allowed_headers": ["Authorization", "Content-Type", "Accept"]
  }
}
```

For production, restrict `allowed_origins` to your actual domain(s):

```json
{
  "cors": {
    "allowed_origins": ["https://myapp.example.com"]
  }
}
```

OPTIONS preflight requests pass through the auth filter without requiring a token.

---

## Examples

### cURL

```bash
TOKEN="your-token-here"

# Start a new conversation
curl -s -X POST http://localhost:8081/api/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "What is the current date and time?"}' | jq .

# Continue the conversation
curl -s -X POST http://localhost:8081/api/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "Now create a file with that info", "session_id": "SESSION_ID"}' | jq .

# List sessions
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/sessions | jq .

# System status
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/status | jq .
```

### Python

```python
import requests

BASE = "http://localhost:8081"
TOKEN = "your-token-here"
HEADERS = {
    "Authorization": f"Bearer {TOKEN}",
    "Content-Type": "application/json",
}

# Start a conversation
resp = requests.post(f"{BASE}/api/chat", json={
    "message": "List all Python files in the home directory"
}, headers=HEADERS)

data = resp.json()
print(data["response"])
session_id = data["session_id"]

# Follow up
resp = requests.post(f"{BASE}/api/chat", json={
    "message": "How many are there?",
    "session_id": session_id,
}, headers=HEADERS)

print(resp.json()["response"])

# View the full conversation
resp = requests.get(f"{BASE}/api/sessions/{session_id}", headers=HEADERS)
for msg in resp.json()["messages"]:
    print(f"[{msg['role']}] {msg['content'][:80]}")
```

### JavaScript / Node.js

```javascript
const BASE = "http://localhost:8081";
const TOKEN = "your-token-here";

async function chat(message, sessionId = null) {
  const body = { message };
  if (sessionId) body.session_id = sessionId;

  const resp = await fetch(`${BASE}/api/chat`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });

  return resp.json();
}

// Usage
const first = await chat("Hello, what tools do you have?");
console.log(first.response);

const second = await chat("Use grep to find TODO comments", first.session_id);
console.log(second.response);
console.log("Tools used:", second.tools_used);
```

### PHP

```php
<?php
$base  = 'http://localhost:8081';
$token = 'your-token-here';

function apiChat(string $message, ?string $sessionId = null): array {
    global $base, $token;

    $body = ['message' => $message];
    if ($sessionId) $body['session_id'] = $sessionId;

    $ch = curl_init("{$base}/api/chat");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

$result = apiChat('What PHP version is installed?');
echo $result['response'] . "\n";

$result = apiChat('And what extensions?', $result['session_id']);
echo $result['response'] . "\n";
```

---

## CLI Commands Reference

| Command | Description |
|---------|-------------|
| `php spark agent:api:token` | Generate or display the API bearer token |
| `php spark agent:api:token --regenerate` | Regenerate the token (invalidates the old one) |
| `php spark agent:api:serve` | Start the dedicated API HTTP server |
| `php spark agent:api:serve --host 127.0.0.1` | Bind to a specific host |
| `php spark agent:api:serve --port 9000` | Use a custom port |
| `php spark agent:api:serve --disable` | Disable the API server |
| `php spark agent:api:serve --enable` | Re-enable the API server |
