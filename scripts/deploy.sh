#!/usr/bin/env bash
#
# Build and (re)deploy the production stack. Safe to re-run: it backs up the database
# before migrating, reseeds reference data idempotently, rolls the containers and
# restarts the queue workers so they pick up the new code.
#
#   ./scripts/deploy.sh            # build and deploy
#   ./scripts/deploy.sh --no-build # redeploy the images already built
#
# A second stack (e.g. staging) on the same host:
#   ENV_FILE=/srv/ntrec-staging/.env PROJECT=ntrec-staging ./scripts/deploy.sh
# (that env file must also set APP_ENV_FILE to its own path).
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

ENV_FILE=${ENV_FILE:-.env}
DC=(docker compose -f compose.prod.yaml --env-file "$ENV_FILE" -p "${PROJECT:-ntrec}")
say() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
die() { printf '\033[31m%s\033[0m\n' "$1" >&2; exit 1; }

[ -f "$ENV_FILE" ] || die "$ENV_FILE is missing. Copy .env.example and fill it in (docs/DEPLOYMENT.md)."
grep -q '^APP_KEY=base64:' "$ENV_FILE" || die "APP_KEY is not set. Generate one with:
  ${DC[*]} run --rm --no-deps -e SKIP_OPTIMIZE=1 --entrypoint php app artisan key:generate --show
and paste it into $ENV_FILE."
grep -q '^APP_ENV=production' "$ENV_FILE" || echo "warning: APP_ENV is not 'production'."
if grep -q '^NGINX_SITE=https' "$ENV_FILE"; then
    cert_dir=$(grep '^TLS_CERT_DIR=' "$ENV_FILE" | cut -d= -f2-); cert_dir=${cert_dir:-./docker/prod/certs}
    [ -f "$cert_dir/fullchain.pem" ] && [ -f "$cert_dir/privkey.pem" ] || die "NGINX_SITE=https but no fullchain.pem/privkey.pem in $cert_dir."
fi

if [ "${1:-}" != "--no-build" ]; then
    say "Building images"
    "${DC[@]}" build --pull
fi

say "Checking the application can read $ENV_FILE"
"${DC[@]}" run --rm --no-deps --entrypoint sh app -c 'test -r .env' \
    || die "The container user (www-data, gid 33) cannot read $ENV_FILE. Run: sudo chown root:33 $ENV_FILE && sudo chmod 640 $ENV_FILE"

say "Starting database and Redis"
"${DC[@]}" up -d --wait mysql redis

if "${DC[@]}" ps --status running --services 2>/dev/null | grep -qx app; then
    say "Backing up the database before migrating"
    "${DC[@]}" exec -T app php artisan db:backup || die "Backup failed; not migrating. Investigate before re-running."
fi

say "Migrating and seeding reference data"
"${DC[@]}" run --rm app php artisan migrate --force
"${DC[@]}" run --rm app php artisan db:seed --class=ReferenceDataSeeder --force

say "Starting the application"
"${DC[@]}" up -d --remove-orphans --wait app web queue scheduler
"${DC[@]}" exec -T app php artisan queue:restart

say "Readiness check"
"${DC[@]}" exec -T app php artisan recruitment:preflight --production || echo "Preflight reported problems — review them above."

say "Done"
"${DC[@]}" ps
