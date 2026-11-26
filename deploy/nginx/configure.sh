#!/bin/sh
set -eu

configuration_dir=/opt/autoqa/nginx
certificate_dir=/etc/letsencrypt/live/qa-app.eastasia.cloudapp.azure.com
active=/etc/nginx/conf.d/default.conf

has_certificate() {
    [ -s "$certificate_dir/fullchain.pem" ] && [ -s "$certificate_dir/privkey.pem" ]
}

case "${1:-startup}" in
    startup)
        template="$configuration_dir/http-only.conf"
        if has_certificate; then
            template="$configuration_dir/https.conf"
        fi
        cp "$template" "$active"
        ;;
    reload)
        if ! has_certificate; then
            printf '%s\n' 'No certificate and private key were found in the Nginx certificate volume.' >&2
            exit 1
        fi

        previous=$(mktemp)
        trap 'rm -f "$previous"' EXIT
        cp "$active" "$previous"
        cp "$configuration_dir/https.conf" "$active"
        if ! nginx -t || ! nginx -s reload; then
            cp "$previous" "$active"
            exit 1
        fi
        ;;
    *)
        printf '%s\n' 'Usage: configure.sh [startup|reload]' >&2
        exit 2
        ;;
esac
