#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
domain=qa-app.eastasia.cloudapp.azure.com

usage() {
    printf '%s\n' 'Usage: ./deploy/tls.sh issue EMAIL | check | renew' \
        'The issue command also accepts an exported CERTBOT_EMAIL.'
}

reload_nginx() {
    docker compose exec -T nginx /bin/sh /opt/autoqa/nginx/configure.sh reload
}

case "${1:-}" in
    issue)
        email="${2:-${CERTBOT_EMAIL:-}}"
        if [ -z "$email" ] || [ "$#" -gt 2 ]; then
            usage >&2
            exit 2
        fi

        docker compose up -d nginx
        docker compose run --rm --no-deps certbot certonly \
            --webroot --webroot-path /var/www/certbot --preferred-challenges http \
            --cert-name "$domain" --domains "$domain" --email "$email" \
            --agree-tos --non-interactive --keep-until-expiring
        reload_nginx
        ;;
    check)
        if [ "$#" -ne 1 ]; then
            usage >&2
            exit 2
        fi
        docker compose run --rm --no-deps certbot renew --cert-name "$domain" --non-interactive --dry-run
        ;;
    renew)
        if [ "$#" -ne 1 ]; then
            usage >&2
            exit 2
        fi
        docker compose run --rm --no-deps certbot renew --cert-name "$domain" --non-interactive
        reload_nginx
        ;;
    -h|--help)
        usage
        ;;
    *)
        usage >&2
        exit 2
        ;;
esac
