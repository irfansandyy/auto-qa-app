# Private browser worker

The Laravel app calls a separate local Playwright process. One Chromium instance and at most one isolated browser context run at a time. Closing the session destroys its cookies; idle sessions expire after 20 minutes. This keeps the browser contribution predictable on the 8 GB server.

## Install and start

From the project root, install dependencies in `worker` and install Playwright Chromium:

```sh
npm --prefix worker install
npm --prefix worker exec playwright install chromium
node worker/runner.mjs
```

The worker loads the project's `.env` with Node's built-in parser. Existing process environment variables take precedence. Node 22 or newer is required.

```dotenv
BROWSER_WORKER_URL=http://127.0.0.1:3001
BROWSER_WORKER_TOKEN=use-a-random-secret-with-at-least-24-characters
BROWSER_WORKER_HOST=127.0.0.1
BROWSER_WORKER_PORT=3001
AUTOQA_LOCAL_TARGETS=http://127.0.0.1:8080,http://localhost:8080
```

Use the same worker token in Laravel and the Node process. Keep it on the server; it is not a user-entered project credential. The worker refuses non-loopback listen addresses and rejects every endpoint, including health, unless its bearer token matches. For separate containers, place both services in a network namespace with a private authenticated forwarding path; this initial worker is designed to run alongside Laravel on the same host.

`BROWSER_EVIDENCE_DIR` optionally overrides the default `storage/app/private/evidence` directory. Files use server-generated filenames beginning with `browser-`, never user-provided paths. Screenshots and traces are private evidence and may contain application data and test credentials; serve them only through authenticated project ownership checks. No more than 20 screenshots are allowed per session.

## Target modes and network boundary

In **public** mode the worker resolves each requested HTTP/HTTPS destination and rejects any private, loopback, metadata/link-local, multicast, reserved, or mixed public/private DNS answer. Redirect destinations and subresources go through the same checks. Every document navigation, including frames, must stay on the selected target origin. Public static assets can come from other public origins. Downloads, service workers, extra browser windows, and WebSockets are disabled for this version.

In **local** mode only exact origins in the administrator's `AUTOQA_LOCAL_TARGETS` are allowed, including subresources. Scheme, hostname, and port all matter: allowing `http://localhost:8080` does not allow `http://127.0.0.1:8080`, a different port, or HTTPS. A project request cannot extend this allowlist. `localhost` refers to the machine running the worker. A website on the user's computer must run alongside the worker or use an administrator-approved tunnel/origin reachable from that worker.

These browser checks are defense in depth, **not a complete network sandbox**. Chromium resolves DNS separately after the application's check; the worker does not pin the checked address to the actual connection, and browser routing cannot cover every browser networking path. Production deployments must also enforce egress at the container/OS boundary, prevent access to the host and cloud metadata, and avoid exposing secrets in the browser environment. Do not claim that these checks alone defeat DNS rebinding. Local target approval intentionally grants access to those origins.

## HTTP contract

All requests require `Authorization: Bearer <BROWSER_WORKER_TOKEN>`. POST bodies must be JSON objects under 64 KiB. Unknown endpoints are rejected.

| Endpoint | Result |
| --- | --- |
| `GET /health` | `status`, `ready`, `browser_installed`, active/max sessions, administrator local origins |
| `POST /sessions` with `{url,mode}` | `{session_id,snapshot,network_errors,console_errors}` |
| `GET /sessions/:id` | `{snapshot}` |
| `POST /sessions/:id/action` | `{snapshot,network_errors,console_errors,assertion?,artifact?,artifacts?,finished?}` |
| `DELETE /sessions/:id` | `{closed:true,artifacts}` |

Snapshots contain `url`, `title`, a bounded 4,000-character visible text extract, and at most 80 visible interactive elements with `id`, `tag`, `role`, `label`, `text`, `type`, and `disabled`. Select options are included in bounded lists. Form values are omitted, password values are never included, and filled passwords are redacted from subsequent textual evidence. When the target document sends a valid `X-AutoQA-Commit` header, the snapshot includes `source_sha` as supporting deployment identity evidence.

Element references change on every snapshot. Use the returned current element ID for the next action, then read its resulting snapshot before another action. Stale references are rejected. Actions cannot evaluate arbitrary JavaScript or run system commands.

| Tool | Input |
| --- | --- |
| `click` | `id` |
| `fill` | `id`, `value` |
| `select` | `id`, option `value` |
| `press` | `key` (`Enter`, `Tab`, `Escape`, `Space`, `ArrowDown`, `ArrowUp`), optional current `id`; otherwise uses keyboard focus |
| `navigate` | same-origin `url` |
| `assert_text` | expected `text` (case-sensitive contains) |
| `assert_url` | exact same-origin `url` |
| `screenshot` | no arguments; returns an `artifact` filename |
| `finish` | no arguments; saves screenshot and trace, returns `artifacts`, ends actions |

The Laravel `BrowserWorker` client uses `start`, `snapshot`, `act`, `screenshot`, `close`, `health`, and `ready`. `ready()` checks configuration without making network calls; `health()` checks the running service and Chromium availability. `snapshot()` unwraps the snapshot; other operations return the complete response object. Calls have a connection timeout and operation deadline; mutation requests are never automatically retried.

## Verification

```sh
npm --prefix worker test
php artisan test --filter=BrowserWorkerTest
```

Node tests cover public/private IPv4 and IPv6, encoded loopback, per-request DNS resolution, local origin boundaries, same-origin navigation, action restrictions, redaction, bearer authentication, JSON/body limits, and HTTP dispatch. Browser integration must additionally be exercised against an allowed local test site after Chromium is installed. The CPU model is separate from this worker; browser tests do not require an inference server.
