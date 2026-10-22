#!/usr/bin/env bash
#
# Development runner for the ABV-IIITM non-teaching recruitment portal.
#
# PHP and MySQL are not assumed to be installed locally, so both run in
# containers alongside Mailpit (to catch outgoing mail) and a queue worker
# (referee invitations are queued). `./dev.sh help` lists the commands.
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

PHP_IMAGE="${NTREC_PHP_IMAGE:-abvrec-php:8.3}"
NETWORK=ntrec-net
DB_CONTAINER=ntrec-mysql
MAIL_CONTAINER=ntrec-mailpit
APP_CONTAINER=ntrec-app
QUEUE_CONTAINER=ntrec-queue
SCHED_CONTAINER=ntrec-scheduler
APP_PORT=8010
DB_PORT=33070
MAIL_UI_PORT=8030

green() { printf '\033[32m%s\033[0m\n' "$1"; }
yellow() { printf '\033[33m%s\033[0m\n' "$1"; }
red() { printf '\033[31m%s\033[0m\n' "$1"; }

php_run() {
    docker run --rm -t \
        --user "$(id -u):$(id -g)" -e HOME=/tmp \
        --network "${NETWORK}" \
        -v "$(pwd)":/app \
        -v /tmp/ntrec-composer:/tmp/composer \
        -w /app "${PHP_IMAGE}" "$@"
}

php_daemon() {
    local name=$1; shift
    docker rm -f "${name}" >/dev/null 2>&1 || true
    docker run -d --name "${name}" --network "${NETWORK}" \
        --user "$(id -u):$(id -g)" -e HOME=/tmp \
        -v "$(pwd)":/app -w /app "$@" >/dev/null
}

ensure_image() {
    if ! docker image inspect "${PHP_IMAGE}" >/dev/null 2>&1; then
        yellow "Building the PHP toolchain image (first run only)…"
        docker build -f docker/php.Dockerfile -t "${PHP_IMAGE}" .
    fi
}

ensure_network() {
    docker network inspect "${NETWORK}" >/dev/null 2>&1 || docker network create "${NETWORK}" >/dev/null
}

ensure_mailpit() {
    if ! docker ps --format '{{.Names}}' | grep -qx "${MAIL_CONTAINER}"; then
        docker rm -f "${MAIL_CONTAINER}" >/dev/null 2>&1 || true
        docker run -d --name "${MAIL_CONTAINER}" --network "${NETWORK}" \
            -p "${MAIL_UI_PORT}:8025" axllent/mailpit:latest >/dev/null
    fi
}

ensure_database() {
    if ! docker ps --format '{{.Names}}' | grep -qx "${DB_CONTAINER}"; then
        if docker ps -a --format '{{.Names}}' | grep -qx "${DB_CONTAINER}"; then
            docker start "${DB_CONTAINER}" >/dev/null
        else
            yellow "Creating the MySQL container…"
            docker run -d --name "${DB_CONTAINER}" --network "${NETWORK}" \
                -e MYSQL_ROOT_PASSWORD=root \
                -e MYSQL_DATABASE=ntrec \
                -e MYSQL_USER=ntrec \
                -e MYSQL_PASSWORD=secret \
                -p "127.0.0.1:${DB_PORT}:3306" mysql:8.4 >/dev/null
        fi
    fi

    printf 'Waiting for MySQL'
    for _ in $(seq 1 90); do
        if docker exec "${DB_CONTAINER}" mysql -uroot -proot -e 'SELECT 1' >/dev/null 2>&1; then
            printf ' ready\n'
            docker exec "${DB_CONTAINER}" mysql -uroot -proot -e \
                "CREATE DATABASE IF NOT EXISTS ntrec_test; GRANT ALL ON ntrec_test.* TO 'ntrec'@'%';" >/dev/null 2>&1
            return
        fi
        printf '.'
        sleep 1
    done

    printf '\n'
    red "MySQL did not become ready. Check: docker logs ${DB_CONTAINER}"
    exit 1
}

case "${1:-help}" in

    setup)
        ensure_image
        ensure_network
        ensure_database
        ensure_mailpit

        [ -f .env ] || { cp .env.example .env; yellow "Created .env from .env.example"; }

        sed -i \
            -e 's/^APP_ENV=production/APP_ENV=local/' \
            -e 's/^APP_DEBUG=false/APP_DEBUG=true/' \
            -e "s#^APP_URL=.*#APP_URL=http://localhost:${APP_PORT}#" \
            -e "s/^DB_HOST=.*/DB_HOST=${DB_CONTAINER}/" \
            -e 's/^DB_PORT=.*/DB_PORT=3306/' \
            -e 's/^DB_DATABASE=.*/DB_DATABASE=ntrec/' \
            -e 's/^DB_USERNAME=.*/DB_USERNAME=ntrec/' \
            -e 's/^DB_PASSWORD=.*/DB_PASSWORD=secret/' \
            -e 's/^SESSION_SECURE_COOKIE=true/SESSION_SECURE_COOKIE=false/' \
            -e 's/^FORCE_HTTPS=.*/FORCE_HTTPS=false/' \
            -e 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' \
            -e 's/^CACHE_STORE=.*/CACHE_STORE=database/' \
            -e 's/^LOG_LEVEL=.*/LOG_LEVEL=debug/' \
            -e "s#^BACKUP_PATH=.*#BACKUP_PATH=$(pwd)/storage/app/backups#" \
            -e 's/^MAIL_USERNAME=.*/MAIL_USERNAME=/' \
            -e 's/^BACKUP_DUMP_OPTIONS=.*/BACKUP_DUMP_OPTIONS=--skip-ssl-verify-server-cert/' \
            -e 's/^MAIL_MAILER=.*/MAIL_MAILER=smtp/' \
            -e "s/^MAIL_HOST=.*/MAIL_HOST=${MAIL_CONTAINER}/" \
            -e 's/^MAIL_PORT=.*/MAIL_PORT=1025/' \
            -e 's/^MAIL_SCHEME=.*/MAIL_SCHEME=null/' \
            -e "s/^SANCTUM_STATEFUL_DOMAINS=.*/SANCTUM_STATEFUL_DOMAINS=localhost:${APP_PORT},localhost:5173,127.0.0.1:${APP_PORT}/" \
            .env

        php_run composer install
        grep -q '^APP_KEY=base64' .env || php_run php artisan key:generate
        npm install
        npm run build

        php_run php artisan migrate:fresh --seed --force
        php_run php artisan db:seed --class=DemoSeeder --force

        green "Setup complete. Run ./dev.sh start"
        ;;

    start)
        ensure_image
        ensure_network
        ensure_database
        ensure_mailpit

        php_daemon "${APP_CONTAINER}" -p "${APP_PORT}:8000" "${PHP_IMAGE}" \
            php artisan serve --host=0.0.0.0 --port=8000
        php_daemon "${QUEUE_CONTAINER}" "${PHP_IMAGE}" \
            php artisan queue:work --queue=mail,default --tries=5 --backoff=30,120,600
        php_daemon "${SCHED_CONTAINER}" "${PHP_IMAGE}" \
            php artisan schedule:work

        sleep 3
        if curl -sf -o /dev/null "http://localhost:${APP_PORT}/up"; then
            green "Running at http://localhost:${APP_PORT}   (mail: http://localhost:${MAIL_UI_PORT})"
            echo
            echo "  superadmin@iiitm.ac.in   Super Administrator"
            echo "  recruitment@iiitm.ac.in  Recruitment Administrator"
            echo "  scrutiny1@iiitm.ac.in    Scrutiny User"
            echo "  committee1@iiitm.ac.in   Screening Committee Member"
            echo "  applicant@example.com    Applicant (submitted application)"
            echo "  applicant2@example.com   Applicant (draft in progress)"
            echo
            echo "  Password for all: Password@123"
        else
            red "The app did not come up. Check: ./dev.sh logs"
            exit 1
        fi
        ;;

    stop)
        docker rm -f "${APP_CONTAINER}" "${QUEUE_CONTAINER}" "${SCHED_CONTAINER}" >/dev/null 2>&1 || true
        green "Application stopped (database and Mailpit left running)."
        ;;

    down)
        docker rm -f "${APP_CONTAINER}" "${QUEUE_CONTAINER}" "${SCHED_CONTAINER}" "${MAIL_CONTAINER}" >/dev/null 2>&1 || true
        docker stop "${DB_CONTAINER}" >/dev/null 2>&1 || true
        green "Everything stopped."
        ;;

    restart) "$0" stop; "$0" start ;;
    logs) docker logs -f "${APP_CONTAINER}" ;;
    queue-logs) docker logs -f "${QUEUE_CONTAINER}" ;;
    watch) npm run dev ;;
    build) npm run build ;;

    test)
        shift || true
        ensure_network
        ensure_database
        php_run php artisan test "$@"
        ;;

    types) npx vue-tsc --noEmit && green "Type-check clean." ;;

    fresh)
        ensure_database
        php_run php artisan migrate:fresh --seed --force
        php_run php artisan db:seed --class=DemoSeeder --force
        green "Database reset with demo data."
        ;;

    db) docker exec -it "${DB_CONTAINER}" mysql -untrec -psecret ntrec ;;
    artisan) shift; php_run php artisan "$@" ;;
    composer) shift; php_run composer "$@" ;;

    status)
        docker ps -a --filter "name=ntrec" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
        echo
        curl -sf -o /dev/null "http://localhost:${APP_PORT}/up" \
            && green "http://localhost:${APP_PORT} is responding" \
            || yellow "Application is not responding"
        ;;

    help|*)
        cat <<'USAGE'
ABV-IIITM Non-Teaching Recruitment Portal — development runner

  ./dev.sh setup        First time only: install deps, migrate, seed demo data
  ./dev.sh start        Start MySQL, Mailpit, app, queue worker, scheduler
                        → app http://localhost:8010, mail http://localhost:8030
  ./dev.sh stop         Stop app, queue worker and scheduler
  ./dev.sh down         Stop everything
  ./dev.sh restart      Stop and start

  ./dev.sh watch        Vite dev server with hot reload (run alongside start)
  ./dev.sh build        Build frontend assets
  ./dev.sh test         Run the test suite (args are passed through)
  ./dev.sh types        Type-check the frontend
  ./dev.sh fresh        Reset the database and reseed demo data
  ./dev.sh db           MySQL shell
  ./dev.sh logs         Follow application logs
  ./dev.sh queue-logs   Follow queue worker logs
  ./dev.sh status       Show what is running

  ./dev.sh artisan <…>  Any artisan command
  ./dev.sh composer <…> Any composer command
USAGE
        ;;
esac
