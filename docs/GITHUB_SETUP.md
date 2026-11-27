# Configure the GitHub App server integration

This guide is for Auto QA developers and server operators. The website serves the repository installation and connection walkthrough at `/docs/github-app`.

## Register the App and set permissions

1. In GitHub, open **Settings**, then **Developer settings**, then **GitHub Apps**. Select **New GitHub App** to register an app, or open **Edit** next to the app already configured for the server. For an organization-owned app, begin in that organization's settings.
2. Enter a unique name and a homepage URL for your Auto QA deployment. For this installation-only setup, leave **Request user authorization (OAuth) during installation** clear, leave **Callback URL** and **Setup URL** blank, and clear **Active** in the **Webhook** section.
3. In **Repository permissions**, set **Contents** and **Pull requests** to **Read and write** for a deployment that opens repair PRs. GitHub supplies read-only **Metadata**. For source review, Contents read access is enough. Keep unrelated permissions unset.
4. Under **Where can this GitHub App be installed?**, choose **Any account** to let other GitHub accounts install the shared app, or **Only on this account** for an app restricted to its owning account. Save your changes. GitHub may ask existing installations to approve updated permissions. [App registration](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/registering-a-github-app) · [Public and private Apps](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/making-a-github-app-public-or-private) · [Changing App permissions](https://docs.github.com/en/apps/maintaining-github-apps/modifying-a-github-app-registration)

## Configure the key on the Azure VM

Record the numeric **App ID** and the app slug that appears after `github.com/apps/`. For the shared deployment the slug is `pbkk-agentic-qa-bot`. In the App settings, open **Credentials**, then **Key pairs**, then **New key** to download the RSA PEM. Transfer it privately to the VM. Keep the key outside the Git checkout's tracked files and public web directory. [GitHub private key management](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/managing-private-keys-for-github-apps)

From the VM's Auto QA checkout, install the PEM using the deployment's default secrets directory. Replace `/private/path/downloaded-app-key.pem` with the path where you stored the file on the VM.

```bash
sudo install -d -m 0700 -o 1000 -g 1000 secrets
sudo install -m 0400 -o 1000 -g 1000 /private/path/downloaded-app-key.pem secrets/github-app.pem
```

Compose mounts `./secrets` read-only at `/run/autoqa-secrets` in the app and QA queue containers. In the VM's private `.env`, use the numeric **App ID** shown by GitHub and use the container path for the PEM. Keep `GITHUB_APP_INSTALLATION_ID` empty for a shared app so Auto QA can discover each repository's installation.

```dotenv
GITHUB_APP_ID=YOUR_NUMERIC_APP_ID
GITHUB_APP_SLUG=pbkk-agentic-qa-bot
GITHUB_APP_PRIVATE_KEY_PATH=/run/autoqa-secrets/github-app.pem
GITHUB_APP_INSTALLATION_ID=
```

## Grant repository access to the Auto QA account

The server operator should confirm who can use each repository. To find an existing account's numeric ID from a private SSH session, replace `user@example.com` with its registered Auto QA email. The command displays only the ID or `null` if there is no match.

```bash
docker compose exec -T -e XDG_CONFIG_HOME=/tmp -e XDG_DATA_HOME=/tmp app \
  php artisan tinker --execute 'dump(\App\Models\User::query()->where("email", "user@example.com")->value("id"));'
```

In the same `.env`, add the repository under that account ID. This example authorizes one repository for account `7`. Use the actual numeric Auto QA account ID, keep existing grants, and use an array for each account's repositories.

```dotenv
AUTOQA_REPOSITORY_ACCESS='{"7":["owner/target-repo"]}'
```

For operators who should create branches, commit files, open drafts, or merge in Auto QA, grant both repository access and explicit workspace write access to the same account and repository. Leave this setting empty for read-only workspace accounts. Use Contents and Pull requests read/write on the GitHub App and approve any updated permissions on the installation. [Change GitHub App permissions](https://docs.github.com/en/apps/maintaining-github-apps/modifying-a-github-app-registration#changing-the-permissions-of-a-github-app)

Enable optional Checks and Commit statuses read permissions to show GitHub CI details on the pull request review screen. The app requests these permissions as read-only when it creates a repository-scoped token. [GitHub status checks](https://docs.github.com/en/pull-requests/reference/status-checks)

```dotenv
AUTOQA_REPOSITORY_WRITE_ACCESS='{"7":["owner/target-repo"]}'
```

Apply the configuration and verify that the app can read the key.

```bash
docker compose up -d --wait
docker compose exec app sh -c 'test -r /run/autoqa-secrets/github-app.pem && echo "PEM is readable"'
```

For a host deployment outside Docker, set `GITHUB_APP_PRIVATE_KEY_PATH` to a private absolute path readable by the app and queue processes. Refresh cached Laravel configuration if used, then restart the queue workers.

Auto QA signs an App JWT and requests a short-lived installation token scoped to the selected repository and applicable Contents, Pull requests, and Metadata permissions. The installation token supplies the bot identity for supported GitHub operations. Keep the PEM and installation tokens out of commits, reports, model prompts, and worker mounts. [Installation token scope](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-an-installation-access-token-for-a-github-app) · [GitHub bot commit verification](https://docs.github.com/en/authentication/managing-commit-signature-verification/about-commit-signature-verification#signature-verification-for-bots)

## Verified repair and draft pull requests

The standard Azure Compose stack supports repository review and repair suggestions. Verified draft PRs require a separately configured isolated repair runner. Publication requires a passing baseline suite, a regression assertion that fails on the baseline, a passing patched regression and patched suite, a successful replay of the original browser scenario, and a separate successful browser control. The published change uses a dedicated `agent/fix-*` branch and a draft PR for a person to review and merge. GitHub repository rules can still block publication. [Isolated repair runner](REPAIR_RUNNER.md)

The GitHub client supports at most five text files and 64 KB of combined changes in `app`, `resources`, `routes`, and `tests`. It protects workflow and secret paths, checks for symlinks, and refuses to replace an existing branch. Draft PR creation reuses an existing open PR for the same branch on retry. Repository snapshots go to `storage/app/qa` for isolated verification. The download client restricts the archive redirect to GitHub's expected archive host and keeps the installation token with the API request.

## Troubleshooting

| What you see | What to check |
| --- | --- |
| **Install on GitHub** is missing | Set `GITHUB_APP_SLUG` and recreate the Compose services. |
| **Setup needed** under **GitHub App** | Set the numeric App ID and configure the server-side PEM path. Check that the file exists and is readable inside the app container. |
| Auto QA denies repository access | Verify the account's numeric Auto QA ID and the exact `owner/repository` grant. Apply the `.env` changes, then run **Check connection** again. |
| GitHub HTTP 401 | Check the numeric App ID, RSA key, and server clock. |
| GitHub HTTP 403 or read-only access | Check whether the installation owner approved the updated permissions. For writes, check Contents and Pull requests access, repository rules, and GitHub rate limits. |
| GitHub HTTP 404 or empty repository | Use the repository's root URL and confirm the installation includes it. Add an initial commit if the repository is empty. |
| Installation mismatch | Clear `GITHUB_APP_INSTALLATION_ID` for shared installations, or use the intended installation ID for a dedicated installation. |
| The target website is on localhost | Use an explicitly administrator-approved origin that the browser worker can reach. On Azure, use a reachable staging URL for a site hosted on your laptop. |
| A fix branch already exists | Review the recorded result or start a new run for a new repair branch. |

The optional real GitHub connection test reads the configured demonstration repository. Regular tests use fake responses, and the connection test performs no commits or pull requests.
