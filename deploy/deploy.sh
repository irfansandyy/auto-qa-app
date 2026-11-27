#!/bin/bash
set -euo pipefail

if [[ $# != 3 || ! $1 =~ ^[0-9a-f]{40}$ ]]; then
    printf '%s\n' 'Usage: deploy/deploy.sh REVISION HTTPS_HEALTH_URL LOCAL_ADDRESS' >&2
    exit 2
fi

revision=$1
health_url=$2
health_address=$3
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

if [[ $(git rev-parse --verify HEAD) != "$revision" ]]; then
    printf '%s\n' 'The checkout must match the release being deployed.' >&2
    exit 1
fi

if [[ ! $health_url =~ ^https://([A-Za-z0-9][A-Za-z0-9.-]*)(:([0-9]{1,5}))?/up$ ]]; then
    printf '%s\n' 'The health URL must be an HTTPS /up URL.' >&2
    exit 1
fi
health_host=${BASH_REMATCH[1]}
health_port=${BASH_REMATCH[3]:-443}
if (( 10#$health_port < 1 || 10#$health_port > 65535 )) || [[ ! $health_address =~ ^[0-9]+(\.[0-9]+){3}$ ]]; then
    printf '%s\n' 'Set a valid health-check port and VM-local IPv4 address.' >&2
    exit 1
fi

if git ls-files --error-unmatch -- .env >/dev/null 2>&1 || ! git check-ignore -q -- .env; then
    printf '%s\n' 'The VM environment file must be ignored and untracked.' >&2
    exit 1
fi

export AUTOQA_ENV_FILE="$PWD/.env"
export COMPOSE_PARALLEL_LIMIT=1
compose=(docker compose --env-file "$AUTOQA_ENV_FILE")

printf '%s\n' 'Adding missing production environment settings.'
python3 deploy/sync-env.py .env.production.example "$AUTOQA_ENV_FILE"
"${compose[@]}" config --quiet

printf '%s\n' 'Building the application, browser worker and Nginx images.'
"${compose[@]}" build app browser nginx

printf '%s\n' 'Applying migrations and waiting for the Compose services.'
"${compose[@]}" up -d --no-build --wait --wait-timeout 600

printf '%s\n' 'Checking HTTPS through the local Nginx listener.'
code=$(curl --proto '=https' --noproxy '*' --fail --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --connect-timeout 4 --max-time 8 --retry 6 --retry-all-errors --retry-delay 2 --retry-max-time 40 \
    --resolve "$health_host:$health_port:$health_address" "$health_url")
if [[ $code != 200 ]]; then
    printf '%s\n' 'The HTTPS health endpoint did not return HTTP 200.' >&2
    exit 1
fi
