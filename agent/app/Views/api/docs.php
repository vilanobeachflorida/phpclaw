<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPClaw API Documentation</title>
    <style>
        :root {
            --bg: #0d1117;
            --surface: #161b22;
            --border: #30363d;
            --text: #c9d1d9;
            --text-dim: #8b949e;
            --accent: #58a6ff;
            --green: #3fb950;
            --yellow: #d29922;
            --red: #f85149;
            --purple: #bc8cff;
            --code-bg: #0d1117;
            --radius: 8px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }
        .container { max-width: 960px; margin: 0 auto; padding: 2rem 1.5rem; }
        h1 {
            font-size: 2rem;
            margin-bottom: 0.25rem;
            background: linear-gradient(90deg, var(--accent), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .subtitle { color: var(--text-dim); margin-bottom: 2rem; }
        h2 { font-size: 1.3rem; color: var(--accent); margin: 2rem 0 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; }
        h3 { font-size: 1rem; color: var(--text); margin: 1.5rem 0 0.5rem; }

        .endpoint {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .endpoint-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            cursor: pointer;
            user-select: none;
        }
        .endpoint-header:hover { background: rgba(88, 166, 255, 0.04); }
        .method {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            text-transform: uppercase;
            font-family: monospace;
        }
        .method-get { background: rgba(63, 185, 80, 0.15); color: var(--green); }
        .method-post { background: rgba(88, 166, 255, 0.15); color: var(--accent); }
        .path { font-family: monospace; font-size: 0.95rem; }
        .desc { color: var(--text-dim); font-size: 0.85rem; margin-left: auto; }
        .endpoint-body { padding: 0 1.25rem 1.25rem; display: none; }
        .endpoint.open .endpoint-body { display: block; }
        .endpoint.open .endpoint-header { border-bottom: 1px solid var(--border); }
        .chevron { color: var(--text-dim); transition: transform 0.2s; margin-left: 0.5rem; }
        .endpoint.open .chevron { transform: rotate(90deg); }

        .badge {
            font-size: 0.7rem;
            padding: 0.15rem 0.45rem;
            border-radius: 3px;
            font-weight: 600;
        }
        .badge-auth { background: rgba(210, 153, 34, 0.15); color: var(--yellow); }
        .badge-public { background: rgba(63, 185, 80, 0.15); color: var(--green); }

        table { width: 100%; border-collapse: collapse; margin: 0.5rem 0; }
        th { text-align: left; font-size: 0.75rem; text-transform: uppercase; color: var(--text-dim); padding: 0.4rem 0.6rem; border-bottom: 1px solid var(--border); }
        td { padding: 0.4rem 0.6rem; font-size: 0.85rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        td code { background: var(--code-bg); padding: 0.1rem 0.35rem; border-radius: 3px; font-size: 0.8rem; }

        pre {
            background: var(--code-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            overflow-x: auto;
            font-size: 0.82rem;
            line-height: 1.5;
            margin: 0.75rem 0;
        }
        code { font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', Menlo, monospace; }
        .code-label { font-size: 0.7rem; text-transform: uppercase; color: var(--text-dim); margin-bottom: 0.25rem; font-weight: 600; }

        .setup-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .setup-box h3 { margin-top: 0; }
        .setup-step { display: flex; gap: 0.75rem; margin: 0.75rem 0; align-items: flex-start; }
        .step-num {
            background: var(--accent);
            color: var(--bg);
            font-weight: 700;
            font-size: 0.75rem;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 0.15rem;
        }

        /* Try-it panel */
        .try-it {
            background: rgba(88, 166, 255, 0.04);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 1rem;
        }
        .try-it label { font-size: 0.8rem; color: var(--text-dim); display: block; margin-bottom: 0.25rem; }
        .try-it input, .try-it textarea {
            width: 100%;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.5rem 0.75rem;
            color: var(--text);
            font-family: monospace;
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
        }
        .try-it textarea { resize: vertical; min-height: 80px; }
        .try-it button {
            background: var(--accent);
            color: var(--bg);
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .try-it button:hover { opacity: 0.9; }
        .try-it button:disabled { opacity: 0.5; cursor: wait; }
        .try-result { margin-top: 0.75rem; }
        .try-result pre { margin: 0; }

        footer { margin-top: 3rem; padding-top: 1rem; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 0.8rem; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <h1>PHPClaw API</h1>
    <p class="subtitle">REST interface for the PHPClaw agent &mdash; chat, manage sessions, and monitor status.</p>

    <!-- Quick Start -->
    <div class="setup-box">
        <h3>Quick Start</h3>
        <div class="setup-step">
            <span class="step-num">1</span>
            <div>
                <strong>Generate an API token</strong>
                <pre><code>php spark agent:api:token</code></pre>
            </div>
        </div>
        <div class="setup-step">
            <span class="step-num">2</span>
            <div>
                <strong>Start the server</strong>
                <pre><code>php spark serve</code></pre>
            </div>
        </div>
        <div class="setup-step">
            <span class="step-num">3</span>
            <div>
                <strong>Send a message</strong>
                <pre><code>curl -X POST http://localhost:8080/api/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "Hello, what can you do?"}'</code></pre>
            </div>
        </div>
    </div>

    <h2>Authentication</h2>
    <p>All endpoints (except <code>/api/docs</code>) require a Bearer token in the <code>Authorization</code> header:</p>
    <pre><code>Authorization: Bearer &lt;your-token&gt;</code></pre>
    <p style="margin-top: 0.5rem; color: var(--text-dim); font-size: 0.85rem;">
        Generate a token with <code>php spark agent:api:token</code>. The token is stored in <code>writable/agent/config/api.json</code>.
    </p>

    <h2>Endpoints</h2>

    <!-- POST /api/chat -->
    <div class="endpoint" id="ep-chat">
        <div class="endpoint-header" onclick="toggle('ep-chat')">
            <span class="method method-post">POST</span>
            <span class="path">/api/chat</span>
            <span class="badge badge-auth">Auth</span>
            <span class="desc">Send a message to the agent</span>
            <span class="chevron">&#9654;</span>
        </div>
        <div class="endpoint-body">
            <p>Send a user message and receive the agent's response. Provide a <code>session_id</code> to continue a conversation, or omit it to start a new session.</p>

            <h3>Request Body</h3>
            <table>
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td><code>message</code></td><td>string</td><td>Yes</td><td>The user message</td></tr>
                <tr><td><code>session_id</code></td><td>string</td><td>No</td><td>Session ID to continue a conversation</td></tr>
                <tr><td><code>role</code></td><td>string</td><td>No</td><td>Override default role (e.g. <code>reasoning</code>, <code>coding</code>)</td></tr>
                <tr><td><code>module</code></td><td>string</td><td>No</td><td>Override default module</td></tr>
            </table>

            <div class="code-label">Example Request</div>
            <pre><code>curl -X POST http://localhost:8080/api/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "List all PHP files in the current directory",
    "session_id": "20260318-143022-a1b2c3d4"
  }'</code></pre>

            <div class="code-label">Example Response</div>
            <pre><code>{
  "session_id": "20260318-143022-a1b2c3d4",
  "response": "Here are the PHP files in the current directory:\n- index.php\n- spark",
  "usage": {
    "input_tokens": 847,
    "output_tokens": 234,
    "cost": 0.004,
    "tool_calls": 1,
    "elapsed_ms": 1200
  },
  "tools_used": ["dir_list"]
}</code></pre>

            <div class="try-it">
                <label>Token</label>
                <input type="text" id="try-token" placeholder="Paste your Bearer token">
                <label>Message</label>
                <textarea id="try-message" placeholder="Type a message...">Hello! What tools do you have available?</textarea>
                <label>Session ID (optional)</label>
                <input type="text" id="try-session" placeholder="Leave empty to start new session">
                <button onclick="tryChat()" id="try-btn">Send</button>
                <div class="try-result" id="try-result"></div>
            </div>
        </div>
    </div>

    <!-- GET /api/sessions -->
    <div class="endpoint" id="ep-sessions">
        <div class="endpoint-header" onclick="toggle('ep-sessions')">
            <span class="method method-get">GET</span>
            <span class="path">/api/sessions</span>
            <span class="badge badge-auth">Auth</span>
            <span class="desc">List all sessions</span>
            <span class="chevron">&#9654;</span>
        </div>
        <div class="endpoint-body">
            <p>Returns a list of all chat sessions with their metadata.</p>

            <div class="code-label">Example Response</div>
            <pre><code>{
  "sessions": [
    {
      "id": "20260318-143022-a1b2c3d4",
      "name": "api-20260318-143022",
      "created_at": "2026-03-18T14:30:22+00:00",
      "status": "active"
    }
  ],
  "count": 1
}</code></pre>
        </div>
    </div>

    <!-- GET /api/sessions/:id -->
    <div class="endpoint" id="ep-session-detail">
        <div class="endpoint-header" onclick="toggle('ep-session-detail')">
            <span class="method method-get">GET</span>
            <span class="path">/api/sessions/:id</span>
            <span class="badge badge-auth">Auth</span>
            <span class="desc">Get session details and messages</span>
            <span class="chevron">&#9654;</span>
        </div>
        <div class="endpoint-body">
            <p>Returns session metadata and the full message history (user and assistant messages only).</p>

            <div class="code-label">Example Response</div>
            <pre><code>{
  "session": {
    "id": "20260318-143022-a1b2c3d4",
    "name": "api-20260318-143022",
    "status": "active",
    "message_count": 4,
    "created_at": "2026-03-18T14:30:22+00:00",
    "updated_at": "2026-03-18T14:35:10+00:00"
  },
  "messages": [
    { "role": "user", "content": "Hello!", "timestamp": "2026-03-18T14:30:25+00:00" },
    { "role": "assistant", "content": "Hi! How can I help?", "timestamp": "2026-03-18T14:30:28+00:00" }
  ]
}</code></pre>
        </div>
    </div>

    <!-- POST /api/sessions/:id/archive -->
    <div class="endpoint" id="ep-archive">
        <div class="endpoint-header" onclick="toggle('ep-archive')">
            <span class="method method-post">POST</span>
            <span class="path">/api/sessions/:id/archive</span>
            <span class="badge badge-auth">Auth</span>
            <span class="desc">Archive a session</span>
            <span class="chevron">&#9654;</span>
        </div>
        <div class="endpoint-body">
            <p>Archives (closes) a session. Archived sessions are retained but marked inactive.</p>

            <div class="code-label">Example Response</div>
            <pre><code>{
  "status": "archived",
  "session_id": "20260318-143022-a1b2c3d4"
}</code></pre>
        </div>
    </div>

    <!-- GET /api/status -->
    <div class="endpoint" id="ep-status">
        <div class="endpoint-header" onclick="toggle('ep-status')">
            <span class="method method-get">GET</span>
            <span class="path">/api/status</span>
            <span class="badge badge-auth">Auth</span>
            <span class="desc">Health check and system info</span>
            <span class="chevron">&#9654;</span>
        </div>
        <div class="endpoint-body">
            <p>Returns system health, configured providers, available tools, and default settings.</p>

            <div class="code-label">Example Response</div>
            <pre><code>{
  "status": "ok",
  "version": "0.1.0",
  "providers": [
    { "name": "lmstudio", "description": "LM Studio local inference" }
  ],
  "tools": ["file_read", "file_write", "shell_exec", "grep_search", "..."],
  "defaults": {
    "role": "reasoning",
    "module": "reasoning"
  }
}</code></pre>
        </div>
    </div>

    <!-- GET /api/docs -->
    <div class="endpoint" id="ep-docs">
        <div class="endpoint-header" onclick="toggle('ep-docs')">
            <span class="method method-get">GET</span>
            <span class="path">/api/docs</span>
            <span class="badge badge-public">Public</span>
            <span class="desc">This documentation page</span>
            <span class="chevron">&#9654;</span>
        </div>
        <div class="endpoint-body">
            <p>Serves this interactive API documentation page. No authentication required.</p>
        </div>
    </div>

    <h2>Session Flow</h2>
    <p>Sessions maintain conversation context across multiple requests:</p>
    <pre><code># 1. Start a new conversation (no session_id)
RESP=$(curl -s -X POST http://localhost:8080/api/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "Remember: my name is Alice"}')

# 2. Extract the session_id from the response
SESSION=$(echo $RESP | jq -r .session_id)

# 3. Continue the conversation with the same session
curl -X POST http://localhost:8080/api/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"message\": \"What is my name?\", \"session_id\": \"$SESSION\"}"

# 4. View the full conversation
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/sessions/$SESSION

# 5. Archive when done
curl -X POST -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/sessions/$SESSION/archive</code></pre>

    <h2>Error Responses</h2>
    <table>
        <tr><th>Status</th><th>Meaning</th><th>Body</th></tr>
        <tr><td><code>400</code></td><td>Bad request</td><td><code>{"error": "message is required"}</code></td></tr>
        <tr><td><code>401</code></td><td>Missing auth</td><td><code>{"error": "Missing or invalid Authorization header..."}</code></td></tr>
        <tr><td><code>403</code></td><td>Invalid token</td><td><code>{"error": "Invalid API token"}</code></td></tr>
        <tr><td><code>404</code></td><td>Not found</td><td><code>{"error": "Session not found"}</code></td></tr>
        <tr><td><code>503</code></td><td>API disabled / not configured</td><td><code>{"error": "API token not configured", ...}</code></td></tr>
    </table>

    <footer>PHPClaw v0.1.0 &mdash; Terminal-first multi-model AI agent shell</footer>
</div>

<script>
function toggle(id) {
    document.getElementById(id).classList.toggle('open');
}

async function tryChat() {
    const btn    = document.getElementById('try-btn');
    const result = document.getElementById('try-result');
    const token  = document.getElementById('try-token').value.trim();
    const msg    = document.getElementById('try-message').value.trim();
    const sid    = document.getElementById('try-session').value.trim();

    if (!token) { alert('Please enter your API token'); return; }
    if (!msg)   { alert('Please enter a message'); return; }

    btn.disabled = true;
    btn.textContent = 'Sending...';
    result.innerHTML = '';

    const body = { message: msg };
    if (sid) body.session_id = sid;

    try {
        const resp = await fetch('/api/chat', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + token,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(body),
        });
        const data = await resp.json();
        result.innerHTML = '<pre><code>' + escapeHtml(JSON.stringify(data, null, 2)) + '</code></pre>';

        // Auto-fill session ID for follow-up
        if (data.session_id) {
            document.getElementById('try-session').value = data.session_id;
        }
    } catch (e) {
        result.innerHTML = '<pre style="color:var(--red)"><code>' + escapeHtml(e.message) + '</code></pre>';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Send';
    }
}

function escapeHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
