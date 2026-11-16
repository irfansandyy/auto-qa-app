# Auto QA

A Laravel 13, Breeze, and Livewire 4 app that tests websites, proposes repairs, verifies changes, opens draft pull requests, and exposes a token-protected chat API.

The operator supplies a GitHub repository, a reachable website (including approved localhost addresses), a goal, and expected behavior. GitHub writes use a GitHub App installed on that repository. A separate bot account is unnecessary.

## Current implementation

- Breeze authentication with first-operator registration, login, logout, and single-operator enforcement.
- Authenticated project setup/editing, owner-scoped history and cancellation.
- Token-protected chat API, SSE streaming, bounded history, and optional model fallback.
- Stored QA transcripts with messages and structured browser tool logs.
- Private Playwright worker with bounded snapshots, current-page element references, screenshots/traces, and exact local-origin approval.
- Model client using an OpenAI-compatible endpoint (campus gateway or local Ollama); structured decisions are validated before browser actions.
- Findings backed by HTTP server errors or deterministic browser assertions, with private evidence links.
- Source diagnosis tied to the website's exact deployed GitHub commit, bounded full source reads, and a small patch with a new regression test.
- Isolated Docker verification and publication gates. Each required result must pass and match the source commit and patch digest before a repair branch and draft PR are created.
- GitHub App JWT/installation authentication, source retrieval, restricted new repair branches, bot attribution, and draft PR creation.

A model's success claim alone does not complete a run. A model can still choose an incomplete or unsuitable journey; the report identifies the recorded coverage. Model output is a proposed action or patch, not a verification result.

## Requirements

| Component | Requirement |
|---|---|
| PHP | 8.3 or later with SQLite, cURL, OpenSSL, mbstring, and XML extensions. Development here uses PHP 8.5. |
| Composer | 2.x |
| Node.js | 22 or later, with npm |
| Browser | A Chromium-compatible Linux environment. Playwright can download Chromium, but the host may still need its shared libraries. |
| Model | Any OpenAI-compatible chat-completions endpoint. The default is the campus LLM gateway; local Ollama is also supported. |
| Docker | Optional. Required only for automated repair verification before publishing a PR. |
| GitHub App | Optional for a basic QA run. Required to inspect source, publish a repair branch, or open a draft PR. |

The app itself can start without Docker or a GitHub App. A QA run still needs a reachable model endpoint and the browser worker. The queue worker is required for every QA run.

## Setup and run locally

Follow these steps from a fresh clone. Steps 1-9 prepare the installation; step 10 starts the three long-running processes.

### 1. Clone the repository

```bash
git clone https://github.com/hisyamssyr/auto-qa-app.git
cd auto-qa-app
```

If you already have the project directory, start from it instead.

### 2. Install PHP dependencies

```bash
composer install
```

Breeze scaffolding is already committed. Do not run `php artisan breeze:install` unless you intentionally want to overwrite the existing authentication views.

### 3. Create the environment file and application key

```bash
cp .env.example .env
php artisan key:generate
```

On Windows, use `copy .env.example .env` instead of `cp`. Do not reuse an existing application key from another installation.

### 4. Generate the shared browser-worker token

The Laravel app and the Playwright worker must share one private token. Generate a random value:

```bash
openssl rand -hex 24
```

Open `.env` and set it:

```dotenv
BROWSER_WORKER_TOKEN=<paste-the-generated-value>
```

The token must be at least 24 characters. Both processes read the same project `.env`, so no separate worker configuration file is needed. Keep the token out of commits, screenshots, and logs.

### 5. Create the SQLite database and run migrations

```bash
touch database/database.sqlite
php artisan migrate
```

On Windows PowerShell, use `New-Item -ItemType File database/database.sqlite -Force`. The default configuration stores sessions, cache, and queued jobs in SQLite. Migrations also create the project, QA run, chat transcript, and Sanctum token tables.

### 6. Install frontend dependencies and build assets

```bash
npm ci
npm run build
```

For active UI development, `npm run dev` can run Vite in watch mode in a separate terminal. A production-style build is still required for the normal setup.

### 7. Install the Playwright worker and Chromium

```bash
cd worker
npm ci
npx playwright install chromium
cd ..
```

On a fresh Debian/Ubuntu machine, Chromium may still be missing system libraries. If the worker reports a browser launch failure, install them with:

```bash
cd worker
npx playwright install --with-deps chromium
cd ..
```

This may require sudo privileges.

### 8. Configure the model endpoint

The default `.env.example` points to the campus LLM gateway. Set the API key before running QA or chat requests:

```dotenv
MODEL_URL=https://senopati.its.ac.id/llm-gateway/v1
MODEL_NAME=qwen3:14b
MODEL_API_KEY=<your-campus-key>
MODEL_TIMEOUT=180
```

The app does not start the model server. To use a local Ollama instance instead, override the same values with an OpenAI-compatible Ollama endpoint:

```dotenv
MODEL_URL=http://127.0.0.1:11434/v1
MODEL_NAME=qwen3:14b
MODEL_API_KEY=ollama
```

Optional fallback providers are configured with `FALLBACK_*` and `FALLBACK_ENABLED=true`. Fallback is attempted only for connection failures, timeouts, and HTTP 5xx responses; 4xx responses are never retried.

### 9. Approve local websites (only when testing localhost)

Public HTTP/HTTPS targets do not need extra configuration. For a local target, add its exact origin to `.env`:

```dotenv
AUTOQA_LOCAL_TARGETS=http://127.0.0.1:8081
```

Scheme, hostname, and port must match exactly. `http://127.0.0.1:8081` does not allow `http://localhost:8081`, HTTPS, or another port. Separate multiple origins with commas. The worker must be restarted after changing this value.

`localhost` means the machine running the browser worker. A website on your laptop is reachable only if it runs alongside the worker or is exposed through an approved tunnel or staging deployment.

### 10. Start the three processes

Keep all three terminals running while using the app.

**Terminal 1 - browser worker**

```bash
cd worker
npm start
```

Wait for:

```text
Private Auto QA browser worker ready on 127.0.0.1:3001.
```

**Terminal 2 - Laravel web server**

From the project root:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

If you change the host or port, update `APP_URL` in `.env` to match. Keep `PHP_CLI_SERVER_WORKERS=4` in `.env` so an SSE chat stream does not block other requests with the built-in server.

**Terminal 3 - queue worker**

From the project root:

```bash
php artisan queue:work --timeout=1800 --tries=0 --sleep=1
```

QA runs are queued jobs. Without this process, a run stays in `queued` and never reaches the browser. `DB_QUEUE_RETRY_AFTER=1860` is intentionally greater than the 1800-second job timeout. Run only one heavyweight queue worker; a shared lock serializes heavy jobs.

Restart the worker and queue after changing `.env`, the model endpoint, the worker token, or `AUTOQA_LOCAL_TARGETS`.

### 11. Open the app and register the first operator

Open [http://127.0.0.1:8000](http://127.0.0.1:8000). Register the first operator account. The password must be at least 12 characters.

Registration closes permanently after the first account exists. To reset a local development installation, run `php artisan migrate:fresh` and visit `/register` again. This deletes all projects, runs, findings, and chat transcripts.

### 12. Create a project and start a QA run

From the dashboard, create a project and fill in:

- **Name** - a label for the project.
- **Repository URL** - an HTTPS GitHub URL such as `https://github.com/owner/repository`.
- **Website URL** - the deployed site, or an exact local origin approved in step 9.
- **Target mode** - `public` or `local`.
- **Goal** - what the browser agent should accomplish.
- **Expected behavior** - the behavior that must be true after a successful journey.
- **Source version and branch** - optional base branch and the deployed 40-character commit SHA. A SHA is required before automatic repair; a branch name alone is not proof of the deployed source.
- **Repair policy** - `Suggest only` saves a proposed patch without publishing. `Verified draft PR` requires the Docker runner and GitHub App.

Save the project, then choose **Start run**. The dashboard shows model, browser, and GitHub readiness. A run consumes browser actions and model calls, so the worker and queue must both be running.

### 13. Optional - create a chat API token

Open **Settings -> API tokens**, create a token, and copy it once. It receives the `chat` ability. Test the non-streaming endpoint:

```bash
curl http://127.0.0.1:8000/api/chat-ai \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"Explain Laravel middleware."}'
```

Streaming uses the same request body at `POST /api/chat-ai/stream`. Session history is available at `GET /api/chat-sessions` and the HTML transcript at `/chat-sessions/{id}`. See [docs/CHAT_API.md](docs/CHAT_API.md) for the complete request, SSE, and error reference.

## Connect a GitHub repository

A GitHub App is required only when the app needs to read repository source, create a repair branch, or open a draft PR. Follow [GitHub setup](docs/GITHUB_SETUP.md) to register an App, grant Contents and Pull requests read/write access, install it on the selected repository, and save the private RSA key outside the repository.

Configure the values privately in `.env`:

```dotenv
GITHUB_APP_ID=<numeric-app-id>
GITHUB_APP_SLUG=<app-slug>
GITHUB_APP_PRIVATE_KEY_PATH=/absolute/private/path/autoqa-app.pem
# Optional when one installation should be pinned:
GITHUB_APP_INSTALLATION_ID=
GITHUB_API_VERSION=2026-03-10
```

The connections panel links to App registration and installation. Actual repository access is checked per project. A missing or invalid App configuration blocks source diagnosis and PR publication, not a basic browser run that produces no finding.

## Prepare automatic repair verification

Automatic repair requires Docker on the same host as Laravel and a trusted, target-specific runner image. Follow [repair runner setup](docs/REPAIR_RUNNER.md), then configure:

```dotenv
AUTOQA_RUNNER_IMAGE=autoqa-target:demo
AUTOQA_RUNNER_TIMEOUT=240
AUTOQA_PREVIEW_ORIGIN=http://127.0.0.1:8081
AUTOQA_LOCAL_TARGETS=http://127.0.0.1:8081
```

The runner image must already contain the target's locked dependencies and disposable fixtures. Repository code never installs dependencies or runs on the orchestrator host. Publication requires all six gates: the original suite, the regression assertion failing on original source, the repaired regression, the repaired suite, the original browser replay, and an independent normal control journey. Human review and merging remain on GitHub.

## Verify the installation

```bash
php artisan test --compact
npm run build
npm --prefix worker test
```

The Laravel suite uses fake outbound GitHub, model, and browser responses. It does not prove real access to a repository, model, Docker daemon, or website. The worker's real-Chromium integration test is skipped by default; enable it after installing Chromium with:

```bash
AUTOQA_REAL_BROWSER_TEST=1 npm --prefix worker test
```

## Troubleshooting

- **`BROWSER_WORKER_TOKEN must be configured with at least 24 characters`** - set a token from `openssl rand -hex 24` in `.env`, then restart the worker and queue.
- **Browser worker unreachable or unauthenticated** - confirm `npm start` is running in `worker`, check `BROWSER_WORKER_URL`/`BROWSER_WORKER_HOST`/`BROWSER_WORKER_PORT`, and make sure Laravel and the worker use the same token.
- **Chromium fails to launch** - run `npx playwright install --with-deps chromium` inside `worker`.
- **Run stays in `queued`** - start `php artisan queue:work --timeout=1800 --tries=0 --sleep=1` from the project root.
- **Local website rejected** - enable `local` target mode and add the exact origin to `AUTOQA_LOCAL_TARGETS`. Restart the worker and queue after editing `.env`.
- **Model unavailable** - verify `MODEL_URL`, `MODEL_NAME`, and `MODEL_API_KEY`; start Ollama or the gateway access separately.
- **`no such table` or empty app state** - confirm `database/database.sqlite` exists and run `php artisan migrate`.
- **Registration page redirects to login** - the first operator already exists. Use the existing account, or reset local data with `php artisan migrate:fresh` if data loss is acceptable.
- **GitHub 401/403/404 errors** - check the App ID, private key path, permissions, installation selection, and repository URL in [GitHub setup](docs/GITHUB_SETUP.md).

## Deployment notes

The development server is for local use only. In deployment, use a process supervisor, a normal Laravel web server with HTTPS, a persistent queue worker, and the Playwright worker on a loopback or private authenticated network. Browser URL validation is defense in depth, not a substitute for operating-system egress restrictions; hostile target DNS changes require network-level controls. Use owned staging applications and disposable accounts/data.

The first version handles small Laravel PHP/Blade repairs (up to five files / 64 KB, source candidates up to 6 KB each), one new feature regression test, and sequential journeys. It does not support arbitrary project stacks, merge or deploy repairs, or receive GitHub webhooks.

Design documents: [Week 11 PRD](docs/WEEK_11.md), [chat API](docs/CHAT_API.md), [browser worker](docs/BROWSER_WORKER.md).
