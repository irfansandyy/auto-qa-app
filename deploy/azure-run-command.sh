#!/bin/bash
set -euo pipefail

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
config_path=/etc/autoqa/deploy.conf

if [[ $# != 1 || ! "$1" =~ ^[0-9a-f]{40}$ ]]; then
    printf '%s\n' 'Expected one full, lowercase Git commit SHA.' >&2
    exit 2
fi
revision=$1
trap 'if (( $? != 0 )); then printf "AUTOQA_DEPLOY_FAILED %s setup\n" "$revision"; fi' EXIT

if [[ $EUID != 0 || ! -f "$config_path" || -L "$config_path" || ! -O "$config_path" ]]; then
    printf '%s\n' 'Install the runner and its root-owned configuration on the VM first.' >&2
    exit 1
fi
config_mode=$(stat -c '%a' -- "$config_path")
if (( (8#$config_mode & 022) != 0 )); then
    printf '%s\n' 'The deployment configuration must be writable only by root.' >&2
    exit 1
fi

declare -A settings=()
while IFS= read -r entry || [[ -n "$entry" ]]; do
    entry=${entry%$'\r'}
    [[ $entry =~ ^[[:space:]]*(#|$) ]] && continue
    key=${entry%%=*}
    case "$key" in
        DEPLOY_USER|REPO_DIR|GITHUB_REPOSITORY|HEALTH_URL|HEALTH_ADDRESS) ;;
        *) printf '%s\n' 'The deployment configuration has an unsupported setting.' >&2; exit 1 ;;
    esac
    if [[ $entry != *=* || -v settings[$key] ]]; then
        printf '%s\n' 'Use one KEY=value declaration per deployment setting.' >&2
        exit 1
    fi
    settings[$key]=${entry#*=}
done < "$config_path"

deploy_user=${settings[DEPLOY_USER]:-}
repo_dir=${settings[REPO_DIR]:-}
github_repository=${settings[GITHUB_REPOSITORY]:-}
health_url=${settings[HEALTH_URL]:-}
health_address=${settings[HEALTH_ADDRESS]:-127.0.0.1}

if [[ ! $deploy_user =~ ^[a-z_][a-z0-9_-]*$ || $repo_dir != /* ||
    ! $github_repository =~ ^[A-Za-z0-9-]+/[A-Za-z0-9_.-]+$ || -z $health_url ]]; then
    printf '%s\n' 'Complete the user, checkout, repository, and health settings on the VM.' >&2
    exit 1
fi
if ! user_record=$(getent passwd "$deploy_user"); then
    printf '%s\n' 'The configured deploy user does not exist on the VM.' >&2
    exit 1
fi
IFS=: read -r _ _ deploy_uid _ _ deploy_home _ <<< "$user_record"
if [[ ! $deploy_uid =~ ^[1-9][0-9]*$ || $deploy_home != /* ]]; then
    printf '%s\n' 'The deploy user must have an unprivileged account and a home directory.' >&2
    exit 1
fi

exec runuser -u "$deploy_user" -- env -i \
    PATH="$PATH" HOME="$deploy_home" USER="$deploy_user" LOGNAME="$deploy_user" GIT_TERMINAL_PROMPT=0 \
    /bin/bash -s -- "$repo_dir" "$github_repository" "$revision" "$health_url" "$health_address" <<'CHECKOUT'
set -euo pipefail
umask 077
checkout=$1
expected_repository=$2
revision=$3
health_url=$4
health_address=$5
stage=checkout
exec 3>&1
exec 2>/dev/null
trap 'result=$?; if (( result != 0 )); then printf "AUTOQA_DEPLOY_FAILED %s %s\n" "$revision" "$stage" >&3; fi' EXIT

cd -P -- "$checkout"
[[ $(git rev-parse --show-toplevel) == "$PWD" ]]
git_dir=$(git rev-parse --absolute-git-dir)
# Lock the existing checkout across Actions retries and operator invocations.
stage=lock
exec 9>>"$git_dir/autoqa-deploy.lock"
flock --exclusive --timeout 300 9

stage=checkout
log_file=$git_dir/autoqa-deploy.log
[[ ! -L $log_file ]]
: > "$log_file"
chmod 0600 -- "$log_file"
exec >>"$log_file" 2>&1
printf 'Release requested %s at %s\n' "$revision" "$(date -u +'%FT%TZ')"

[[ $(git symbolic-ref --quiet --short HEAD) == main ]] || {
    printf '%s\n' 'The existing checkout must be on main.' >&2
    exit 1
}
[[ -z $(git status --porcelain --untracked-files=no) ]] || {
    printf '%s\n' 'The checkout has local changes to tracked files.' >&2
    exit 1
}

origin=$(git remote get-url origin)
origin=${origin,,}
origin=${origin%/}
origin=${origin%.git}
expected_repository=${expected_repository,,}
case "$origin" in
    "https://github.com/$expected_repository"|"git@github.com:$expected_repository"|"ssh://git@github.com/$expected_repository") ;;
    *) printf '%s\n' 'The origin must be the configured GitHub repository without embedded credentials.' >&2; exit 1 ;;
esac

stage=fetch
git fetch --no-tags origin '+refs/heads/main:refs/remotes/origin/main'
remote_tip=$(git rev-parse --verify refs/remotes/origin/main)
if [[ $revision != "$remote_tip" ]]; then
    if git merge-base --is-ancestor "$revision" "$remote_tip"; then
        printf '%s\n' 'A newer revision is on origin/main.'
        printf 'AUTOQA_DEPLOY_SKIPPED %s\n' "$revision" >&3
        exit 0
    fi
    printf '%s\n' 'The requested commit is outside the current origin/main history.' >&2
    exit 1
fi

stage=private-env
if git ls-files --error-unmatch -- .env >/dev/null 2>&1 || git cat-file -e "$revision:.env" 2>/dev/null; then
    printf '%s\n' 'The VM environment file must be untracked.' >&2
    exit 1
fi
[[ -f .env && ! -L .env ]] || {
    printf '%s\n' 'Create the VM-local .env before deploying.' >&2
    exit 1
}

stage=fast-forward
git merge --ff-only "$revision"
git check-ignore -q -- .env || {
    printf '%s\n' 'Keep .env ignored in the deployed revision.' >&2
    exit 1
}

stage=compose
/bin/bash deploy/deploy.sh "$revision" "$health_url" "$health_address"

stage=record
stamp=$(mktemp "$git_dir/autoqa-deployed.XXXXXX")
printf '%s\n' "$revision" > "$stamp"
mv -fT -- "$stamp" "$git_dir/autoqa-deployed"
printf 'AUTOQA_DEPLOY_OK %s\n' "$revision" >&3
CHECKOUT
