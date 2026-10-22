#!/bin/sh
# Prepares a container from the immutable image: writable trees on the storage
# volume and Laravel's config/route/view/event caches (built from the mounted .env).
set -e
cd /var/www/html

mkdir -p storage/app/private storage/app/backups storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs storage/fonts bootstrap/cache 2>/dev/null || true

if [ ! -f .env ]; then
    echo "entrypoint: /var/www/html/.env is missing. Mount it (see compose.prod.yaml)." >&2
    exit 1
fi
# An unreadable .env would make Laravel fall back to built-in defaults silently.
if [ ! -r .env ]; then
    echo "entrypoint: .env is not readable by $(id -un) (uid $(id -u)). On the host run:" >&2
    echo "  sudo chown root:$(id -g) .env && sudo chmod 640 .env" >&2
    exit 1
fi

if [ "${SKIP_OPTIMIZE:-0}" != "1" ]; then
    php artisan optimize --quiet
fi

exec "$@"
