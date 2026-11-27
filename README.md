# Auto QA

A Laravel 13 and Livewire 4 app that tests websites, proposes repairs, verifies changes, and opens draft pull requests.

The operator supplies a GitHub repository, a reachable website (including approved localhost addresses), a goal, and expected behavior. GitHub writes use a GitHub App installed on that repository. A separate bot account is unnecessary.

## Current implementation

- First-operator setup, login, authenticated project setup/editing, owner-scoped history and cancellation.
- Private Playwright worker with bounded snapshots, current-page element references, screenshots/traces, and exact local-origin approval.
- CPU model client using an OpenAI-compatible local endpoint; structured decisions are validated before browser actions.
- Findings backed by HTTP server errors or deterministic browser assertions, with private evidence links.
- Source diagnosis tied to the website's exact deployed GitHub commit, bounded full source reads, and a small patch with a new regression test.
- Isolated Docker verification and publication gates. Each required result must pass and match the source commit and patch digest before a repair branch and draft PR are created.
- GitHub App JWT/installation authentication, source retrieval, restricted new repair branches, bot attribution, and draft PR creation.

A model's success claim alone does not complete a run. A CPU model can still choose an incomplete or unsuitable journey; the report identifies the recorded coverage. Model output is a proposed action or patch, not a verification result.

## Start the workspace

Requirements: PHP 8.3 or later with SQLite, cURL, OpenSSL, mbstring and XML extensions; Composer; Node 22 or later; a Chromium-compatible Linux environment. Development here uses PHP 8.5. Docker is required for automatic repair verification.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
```

Set a unique `BROWSER_WORKER_TOKEN` in `.env` (at least 24 characters; `openssl rand -hex 24` generates one). Both Laravel and the worker read this private file. Do not replace an existing `.env` or application key when updating an installation.

Install the worker's dependencies and browser:

```bash
cd worker
npm ci
npx playwright install chromium
npm start
```

Keep this process running. In another terminal at the project root:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open [the local workspace](http://127.0.0.1:8000) and create an operator account. Run the QA queue worker in a separate terminal:

```bash
php artisan queue:work --timeout=1800 --tries=0 --sleep=1
```

`DB_QUEUE_RETRY_AFTER=1860` exceeds the job timeout. A shared lock serializes heavy jobs. Restart the worker after changing configuration. For deployment, use a process supervisor, a normal Laravel web server and HTTPS; the development server is for local use.

For the production Docker Compose stack, Nginx, and certificate setup, follow the [Azure VM deployment guide](docs/AZURE_DEPLOYMENT.md).

## Automatic deployments to Azure

GitHub Actions runs **Quick tests** on pull requests and pushes to `main`. The test commands have a 55-second ceiling after PHP and Composer setup. When a push to `main` passes, the **Deploy to Azure** job uses GitHub OpenID Connect to invoke the VM runner. The VM fetches the tested commit, appends missing settings from `.env.production.example`, rebuilds the images, runs the Compose migration service, and checks HTTPS at `/up`.

Keep the real `.env` on the VM. Add future variable names and safe defaults to the tracked `.env.production.example`. Leave values for new passwords, keys, and tokens blank in that template and fill them in privately on the VM. Existing values, including intentionally blank settings, are preserved. A build failure leaves the existing containers running. The VM records a release only after the HTTPS check passes.

### Install the VM runner once

From the existing production checkout on the VM, run these commands after the workflow and deployment files reach `main`. Use the same checkout and Docker Compose project that already hold the app's data and certificate volumes.

```bash
git pull --ff-only origin main
docker compose version
python3 --version
chmod 600 .env
sudo install -d -m 0755 /etc/autoqa
sudo install -o root -g root -m 0755 deploy/azure-run-command.sh /usr/local/sbin/autoqa-deploy
sudo test -e /etc/autoqa/deploy.conf || sudo install -o root -g root -m 0600 /dev/null /etc/autoqa/deploy.conf
sudoedit /etc/autoqa/deploy.conf
```

Enter the actual Linux username and the absolute path of the existing checkout. The account must own that checkout, read the VM-local `.env`, and already be able to use Docker without `sudo`. The checkout must be on `main` with no local changes to tracked files. For a private repository, configure a read-only GitHub deploy key for that Linux account. [GitHub deploy keys](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys)

```dotenv
DEPLOY_USER=YOUR_LINUX_USER
REPO_DIR=/home/YOUR_LINUX_USER/auto-qa-app
GITHUB_REPOSITORY=irfansandyy/auto-qa-app
HEALTH_URL=https://qa-app.eastasia.cloudapp.azure.com/up
HEALTH_ADDRESS=127.0.0.1
```

Use the VM-local IPv4 address Nginx binds to for `HEALTH_ADDRESS` if the stack uses a specific interface. Keep the HTTPS URL aligned with the certificate. The VM Guest Agent must be ready and able to reach Azure. [Azure Linux Run Command](https://learn.microsoft.com/en-us/azure/virtual-machines/linux/run-command)

### Authorize GitHub Actions in Azure once

In GitHub, open **Settings**, **Environments**, then create `production`. Set **Deployment branches and tags** to selected branches and add only `main`. Protect `main` and require the **Quick tests** status check. Run Command can run privileged scripts on the VM assigned to the Azure identity, so keep the identity dedicated to this workflow. [GitHub deployment environments](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments)

Create a user-assigned managed identity in Azure and add a GitHub federated credential for the `production` environment. For the legacy OIDC subject format, use `repo:irfansandyy/auto-qa-app:environment:production`. If your repository uses immutable OIDC claims, get the exact subject with the GitHub CLI command below and use its output instead. GitHub uses the immutable format for repositories created after July 15, 2026 and some older repositories. [GitHub OIDC subject formats](https://docs.github.com/en/actions/reference/security/oidc#immutable-subject-claims)

```bash
gh api repos/irfansandyy/auto-qa-app --jq '"repo:\(.owner.login)@\(.owner.id)/\(.name)@\(.id):environment:production"'
```

Run the following in Bash in Azure Cloud Shell with an account allowed to create the identity, custom roles, and role assignments. Replace the resource group, VM name, and OIDC subject with the values for your deployment. [Azure OIDC with GitHub Actions](https://learn.microsoft.com/en-us/azure/developer/github/connect-from-azure-openid-connect)

```bash
resource_group=YOUR_AZURE_RESOURCE_GROUP
vm_name=YOUR_AZURE_VM_NAME
oidc_subject='YOUR_GITHUB_OIDC_SUBJECT'
identity=autoqa-github-production

subscription=$(az account show --query id -o tsv)
tenant=$(az account show --query tenantId -o tsv)
subscription_scope="/subscriptions/$subscription"
group_scope="$subscription_scope/resourceGroups/$resource_group"
vm_id=$(az vm show -g "$resource_group" -n "$vm_name" --query id -o tsv)
location=$(az vm show -g "$resource_group" -n "$vm_name" --query location -o tsv)

az identity create -g "$resource_group" -n "$identity" -l "$location" --output none
az identity federated-credential create -g "$resource_group" --identity-name "$identity" \
  --name github-production --issuer https://token.actions.githubusercontent.com \
  --subject "$oidc_subject" --audiences api://AzureADTokenExchange --output none
principal=$(az identity show -g "$resource_group" -n "$identity" --query principalId -o tsv)
client=$(az identity show -g "$resource_group" -n "$identity" --query clientId -o tsv)

run_role=$(jq -cn --arg scope "$group_scope" --arg name "Auto QA Deploy - $resource_group" \
  '{Name:$name,Description:"Run Auto QA deployment on the assigned VM",Actions:["Microsoft.Compute/virtualMachines/read","Microsoft.Compute/virtualMachines/runCommand/action"],AssignableScopes:[$scope]}')
read_role=$(jq -cn --arg scope "$subscription_scope" --arg name "Auto QA Deployment Status - $resource_group" \
  '{Name:$name,Description:"Read Azure Compute async deployment status",Actions:["Microsoft.Compute/locations/operations/read","Microsoft.Resources/subscriptions/read"],AssignableScopes:[$scope]}')
run_role_id=$(az role definition create --role-definition "$run_role" --query name -o tsv)
read_role_id=$(az role definition create --role-definition "$read_role" --query name -o tsv)

az role assignment create --assignee-object-id "$principal" --assignee-principal-type ServicePrincipal \
  --role "$run_role_id" --scope "$vm_id" --output none
az role assignment create --assignee-object-id "$principal" --assignee-principal-type ServicePrincipal \
  --role "$read_role_id" --scope "$subscription_scope" --output none

printf 'AZURE_CLIENT_ID=%s\nAZURE_TENANT_ID=%s\nAZURE_SUBSCRIPTION_ID=%s\nAZURE_RESOURCE_GROUP=%s\nAZURE_VM_NAME=%s\n' \
  "$client" "$tenant" "$subscription" "$resource_group" "$vm_name"
```

Add the five printed values as **Environment variables** in the GitHub `production` environment. These are Azure identifiers. Azure requires permission to poll the asynchronous operation, which is why the status role is read-only and assigned separately from the single-VM Run Command role. [Azure async operation permissions](https://learn.microsoft.com/en-us/azure/azure-resource-manager/management/async-operations#permission-for-tracking-async-status)

### Verify the first automatic release

In GitHub, open **Actions** and rerun the failed deploy job for the current `main` commit if the first push ran before setup finished. Otherwise, the next successful push to `main` starts the deployment. On the VM, `cat .git/autoqa-deployed` shows the last confirmed commit. Troubleshooting details stay in `.git/autoqa-deploy.log` on the VM. If a new setting needs a secret, edit `.env` privately on the VM and rerun the deployment.

## Connect the model, repository and website

A small model is the starting point for the 8 GB CPU-only budget. One example is Ollama's `qwen3:1.7b`. Run a single local model and keep its context bounded; its repair accuracy and runtime need measurement on your demonstration repository. [Model package](https://ollama.com/library/qwen3:1.7b), [local API compatibility](https://docs.ollama.com/api/openai-compatibility).

```bash
ollama pull qwen3:1.7b
ollama serve
```

The default `MODEL_URL=http://127.0.0.1:11434/v1` and `MODEL_NAME=qwen3:1.7b` match that example. The app does not install or start the model automatically. `MODEL_API_KEY` is optional for a local server and required if your model server enforces authentication.

Follow [GitHub setup](docs/GITHUB_SETUP.md): register an App under your existing account, grant Contents and Pull requests read/write, install it only on the selected repository, and privately configure its App ID and RSA key path. The Connections page includes the registration and installation links. Actual access is checked per project.

Enter the repository and website addresses, goal, and expected behavior. Supply the deployed **40-character commit SHA** under Source version and branch, or expose it in the website's `X-AutoQA-Commit` response header. A branch head alone does not prove which source is deployed. Conflicting versions block repair.

For a local website, add its exact origin to `AUTOQA_LOCAL_TARGETS`, for example `http://127.0.0.1:8080,http://127.0.0.1:8081`. Restart the browser worker and queue after changing it. Localhost refers to the browser server; a remote server needs a tunnel or staging deployment to reach your laptop. The repair preview origin must also be approved. [Worker details](docs/BROWSER_WORKER.md).

## Authorize automatic repair

Choose **Verified draft PR** on a project to authorize a new repair branch, commits and a draft PR after verification. **Suggest only** saves a proposed patch for review.

Follow [repair runner setup](docs/REPAIR_RUNNER.md) to prepare a trusted per-repository Docker image with the target's locked dependencies and disposable fixtures. Set `AUTOQA_RUNNER_IMAGE` to that image. The runner must be prepared by the operator; repository code never installs dependencies or runs on the orchestrator host.

Publication requires all six gates: original suite, regression assertion failure on the original source, repaired regression, repaired suite, original browser replay, and a separate normal control journey. Missing Docker, image, source identity, assertions, or passing results leaves the finding/patch visible and prevents publication. Human review and merging remain on GitHub.

## Verification and limits

```bash
php artisan test --compact
npm run build
cd worker
npm test
```

Automated API/model orchestration tests use fake outbound responses; those do not establish access to a real GitHub repository or a model's ability to repair it. Real container verification requires a running Docker daemon and a prepared runner image. The first version handles small Laravel PHP/Blade repairs (up to five files / 64 KB, source candidates up to 6 KB each), one new feature regression test, and sequential journeys. It does not support arbitrary project stacks, merge or deploy repairs, or receive GitHub webhooks.

Browser URL validation is defense in depth, not a substitute for operating-system egress restrictions; hostile target DNS changes require network-level controls. Use owned staging applications and disposable accounts/data.

Planning documents: [product plan](plans/PRODUCT_PLAN.md), [implementation roadmap](plans/IMPLEMENTATION_ROADMAP.md).
