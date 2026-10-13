# Connect a repository with a GitHub App

You do not need a separate committing account. Register a GitHub App under your existing account or an organization you manage. Every GitHub App has a built-in bot identity. Installation authentication and the commit's author are separate; this implementation discovers the App's bot and attributes repair commits to it. [GitHub App and bot accounts](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/differences-between-github-apps-and-oauth-apps).

## Register and install the App

1. Open GitHub **Settings**, then **Developer settings**, **GitHub Apps**, and **New GitHub App** under your account. For an organization, start in that organization's settings.
2. Choose a unique App name. Set the homepage to your QA application URL or its repository URL. User authorization callbacks are unnecessary for this installation-only integration.
3. Disable **Active** under Webhook for this prototype; it does not receive GitHub events yet.
4. Set repository **Contents: Read and write** and **Pull requests: Read and write**. Metadata read access is automatic. Leave unrelated permissions unset.
5. Keep installation restricted to your account for the initial demonstration and create the App.
6. Record its numeric **App ID**. Open **Credentials**, then **Key pairs** and **New key**, and securely save the downloaded PEM key.
7. Choose **Install App**, select your account, and select **Only select repositories**. Install it on the owned demonstration repository.

GitHub documents the registration controls and key-generation procedure. [Registering a GitHub App](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/registering-a-github-app), [Managing private keys](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/managing-private-keys-for-github-apps).

## Configure this application

Place the PEM key in a private server location outside the repository, public web directory, and repair runner. Give the application process read access. Set these environment values in the server's private configuration:

```dotenv
GITHUB_APP_ID=your_numeric_app_id
GITHUB_APP_PRIVATE_KEY_PATH=/absolute/private/path/autoqa-app.pem
# Optional: pin one installation. The client otherwise discovers it from the repository.
GITHUB_APP_INSTALLATION_ID=
GITHUB_API_VERSION=2026-03-10
```

Use the **App ID**, not the client ID. Keep the PEM and generated tokens out of commits, model prompts, reports, and worker mounts. After changing server environment values, refresh Laravel's configuration cache and restart any long-running queue workers.

The server signs an RS256 App JWT, discovers the repository's installation, and obtains a short-lived installation token scoped to that repository and the Contents/Pull requests/Metadata permissions. Tokens expire after one hour; this client renews its in-memory token before expiry. No persistent personal access token is required. [JWT authentication](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-json-web-token-jwt-for-a-github-app), [Installation access tokens](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-an-installation-access-token-for-a-github-app).

## Connect and verify

Supply a repository URL in the form `https://github.com/owner/repository` and a website URL. The repository must already contain a commit. Repository inspection resolves the default branch and its current commit and reports whether the installation has Contents write access. The tested deployment still needs to be bound to its actual source commit; the latest default-branch commit alone does not establish that correspondence.

Repair publication creates a new `agent/fix-*` branch. The client permits at most five text files and 64 KB of combined file content inside `app`, `resources`, `routes`, and `tests`. It excludes workflows, secret paths, symbolic-link traversal, and existing branch replacement. Draft PR creation reuses an existing open PR for the same branch on retry. The client does not merge PRs, force-push, or write the default branch. Repository rules may still block branch creation or commits.

Repository snapshots download under `storage/app/qa` for the isolated runner. Tokens stay in the GitHub client; authenticated API requests never follow redirects. Snapshot downloads allow only the expected GitHub codeload host and omit the installation token there. Extract and execute source only in the isolated runner, with no App key or orchestrator credentials available.

Before publishing a verified PR, the run must show the failure on the original source, a regression test failing before and passing after the patch, relevant checks passing, and the patched browser replay passing. This API client supplies GitHub operations; the run workflow owns those verification gates.

## What localhost means

- A website on the QA server can be reached through an administrator-approved local origin and port.
- In containers, `localhost` refers to that container. Use an explicitly configured internal service address or host route.
- A cloud QA server cannot reach a website on your laptop through your laptop's `localhost`. Use a tunnel to that application or deploy a staging copy. A website tunnel grants HTTP access; it does not grant desktop or filesystem access.

## Troubleshooting

- **Missing configuration:** set both `GITHUB_APP_ID` and `GITHUB_APP_PRIVATE_KEY_PATH`.
- **HTTP 401:** check the App ID, RSA PEM key, and server clock.
- **HTTP 403:** check Contents/Pull requests permission, installation approval, repository rules, and rate limits.
- **HTTP 404:** verify the URL and that the App is installed on that selected repository.
- **Installation mismatch:** clear or correct `GITHUB_APP_INSTALLATION_ID` when switching installations.
- **Existing fix branch:** resume the recorded result or choose a new run branch; the client will never overwrite it.
- **Empty repository:** create the initial commit first.

App registration, installation, and private-key placement require the actual GitHub account and server configuration. Automated tests use fake GitHub responses and make no external commits or PRs.
