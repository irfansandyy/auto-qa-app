# Chat API

The chat API exposes the configured language model behind Sanctum tokens. It is separate from the QA agent loop: `decide()` still uses structured JSON, while chat endpoints return free text.

## Authentication

1. Sign in to the workspace.
2. Open **Settings → API tokens**.
3. Create a token named for the integration. It receives the `chat` ability.
4. Copy the token once; only its hash is stored.

Send it as a bearer token:

```bash
curl http://127.0.0.1:8000/api/chat-ai \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"Jelaskan middleware Laravel."}'
```

Tokens can be revoked from the same Settings panel.

## Non-stream chat

`POST /api/chat-ai`

```json
{
  "prompt": "Jelaskan middleware Laravel.",
  "session_id": 12
}
```

`session_id` is optional. Omit it to start a new session. The prompt is limited to 1000 characters.

```json
{
  "status": "success",
  "data": {
    "session_id": 12,
    "model_used": "qwen3:14b",
    "prompt": "Jelaskan middleware Laravel.",
    "ai_response": "Middleware adalah ...",
    "total_tokens": 214,
    "timestamp": "2026-11-15 09:30:00"
  }
}
```

Every request stores the user message before calling the model and the assistant message afterwards. The session keeps the last `CHAT_HISTORY_MESSAGES` messages (default 10) plus the themed system prompt.

Qwen3 reasoning is returned by the gateway as `reasoning_content`. The application ignores that field and only exposes `choices.*.message.content`. Do not set a small `CHAT_MAX_TOKENS`; reasoning tokens count toward the limit and can leave the answer empty.

## Streaming

`POST /api/chat-ai/stream` uses the same request body and authentication. The response is SSE:

```bash
curl -N http://127.0.0.1:8000/api/chat-ai/stream \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"Hitung dari 1 sampai 5."}'
```

```text
event: token
data: {"delta":"1"}

event: token
data: {"delta":", 2"}

event: done
data: {"session_id":12,"model_used":"qwen3:14b","total_tokens":214,"failed":false}
```

The complete or partial answer is saved in a `finally` block, including when the client disconnects. `event: error` is emitted if the upstream stream is interrupted. If the provider rejects streaming, the endpoint returns `501` JSON; the non-stream endpoint remains available.

For a local demo with the PHP built-in server, set `PHP_CLI_SERVER_WORKERS=4` before starting `php artisan serve`, otherwise one long stream can block other requests.

## History

Both endpoints require `Authorization: Bearer <token>` and only expose sessions owned by the token user.

| Endpoint | Result |
|---|---|
| `GET /api/chat-sessions` | Paginated sessions with `id`, `title`, `messages_count`, `created_at` |
| `GET /api/chat-sessions/{session}` | Session with `messages[]`, and each message with `tool_execution_logs[]` |

The HTML transcript is available at `GET /chat-sessions/{session}` for the signed-in owner. Queries use eager loading; the number of application queries is constant as messages grow.

## Errors

| Status | Meaning |
|---|---|
| 401 | Missing or invalid token |
| 403 | Missing `chat` ability or foreign history record |
| 422 | Validation failed, including a `session_id` owned by another user |
| 429 | `CHAT_API_THROTTLE` exceeded |
| 501 | Provider rejected SSE streaming |
| 503 | All configured model providers failed |

```json
{
  "status": "error",
  "message": "The language model is unavailable."
}
```

Validation errors additionally include `errors` keyed by field.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `MODEL_URL`, `MODEL_NAME`, `MODEL_API_KEY` | campus gateway / `qwen3:14b` | Primary OpenAI-compatible provider |
| `MODEL_TIMEOUT` | `180` in `.env.example` | Request timeout; raise for `qwen3:14b` |
| `MODEL_JSON_MODE` | `true` | Send `response_format: json_object` from `decide()` |
| `FALLBACK_ENABLED` | `false` | Enable the second provider |
| `FALLBACK_MODEL_URL`, `FALLBACK_MODEL_NAME`, `FALLBACK_MODEL_API_KEY` | empty | Fallback provider |
| `CHAT_SYSTEM_PROMPT` | QA-engineer prompt | Theme for chat requests only |
| `CHAT_HISTORY_MESSAGES` | `10` | Previous user/assistant messages sent to the model |
| `CHAT_MODEL_TEMPERATURE` | empty | Omitted when empty |
| `CHAT_MAX_TOKENS` | empty | Omitted when empty; do not set it low for Qwen3 |
| `CHAT_STREAM_USAGE` | `false` | Send `stream_options.include_usage` |
| `CHAT_API_THROTTLE` | `20` | Requests per minute per user |
| `DB_BUSY_TIMEOUT` | `5000` | SQLite lock wait in milliseconds |
| `DB_JOURNAL_MODE` | `wal` | SQLite journal mode |

Fallback is attempted only on connection failures, timeouts, and HTTP 5xx responses. A 4xx response is returned without trying the fallback. `model_used` always reflects the provider that answered.

## Limitations

- Stream delivery through the campus gateway and the real fallback path were exercised manually; unit tests cover the parsers and failure paths with fake HTTP responses.
- Quota and rate limits of the campus gateway are not known.
- Chat history is retained indefinitely; automatic retention cleanup is out of scope.
- The chat endpoint does not execute tools or mutate QA runs. Use the QA dashboard for that.
