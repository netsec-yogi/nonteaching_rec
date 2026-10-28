# Production deployment

Two supported ways to run the portal on an institute-owned server:

- **A. Docker (recommended).** One immutable image built from this repository, run with
  `compose.prod.yaml`: Nginx, PHP-FPM, queue worker, scheduler, MySQL 8.4, Redis, optional
  ClamAV. This is the same container model as the Hostel Management application.
- **B. Native install** on Ubuntu with Nginx, PHP-FPM, MySQL, Redis and Supervisor
  (sections 1–8 below).

## A. Docker

**Requirements:** Docker Engine 24+ with the Compose plugin, about 3 GB of disk for images,
plus space for documents and backups. TCP 80/443 open to users.

### First installation

```bash
git clone <repository-url> /srv/ntrec && cd /srv/ntrec
cp .env.example .env
```

Edit `.env`. With Docker, these values matter in particular:

| Setting | Value |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` | the public HTTPS address / its host |
| `DB_HOST`, `REDIS_HOST` | `mysql`, `redis` (the service names) |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD` | long random values (`openssl rand -hex 24`) |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `redis` |
| `BACKUP_PATH` | `/var/backups/ntrec` (a named volume) |
| `BACKUP_DUMP_OPTIONS` | `--skip-ssl-verify-server-cert` (the image's MariaDB client and MySQL's self-signed certificate on the internal network) |
| `MAIL_*` | the institutional SMTP server |
| `NGINX_SITE` | `https` (certificates below) or `http` behind an institute TLS proxy (then also `TRUSTED_PROXIES` and `FORCE_HTTPS=true`) |

Generate the application key and make `.env` readable by the container user (www-data,
gid 33) but nobody else. The containers refuse to start otherwise, rather than silently
falling back to defaults:

```bash
docker compose -f compose.prod.yaml run --rm --no-deps -e SKIP_OPTIMIZE=1 --entrypoint php app artisan key:generate --show
# paste the output into APP_KEY in .env, then:
sudo chown root:33 .env && sudo chmod 640 .env
```

For `NGINX_SITE=https`, put `fullchain.pem` and `privkey.pem` in `docker/prod/certs/` (or set
`TLS_CERT_DIR`). Then deploy and create the first Super Administrator:

```bash
./scripts/deploy.sh
docker compose -f compose.prod.yaml exec app php artisan recruitment:create-admin registrar@iiitm.ac.in "Registrar" --designation=Registrar
```

`deploy.sh` checks `.env`, the key, certificates and `.env` readability; builds the images;
starts MySQL and Redis; **backs up the database if the app is already running**; migrates;
seeds reference data (idempotent, so new email templates arrive on upgrade); starts the
app, web, queue and scheduler containers; restarts the workers; and runs
`recruitment:preflight --production`.

### Updates

```bash
git pull && ./scripts/deploy.sh
```

### Everyday commands

```bash
DC="docker compose -f compose.prod.yaml"
$DC ps                                   # status (web has a health check on /up)
$DC logs -f app queue                    # logs (PHP errors and Laravel logs go to stderr)
$DC exec app php artisan queue:failed    # failed jobs; retry with queue:retry all
$DC exec app php artisan db:backup       # on-demand verified backup (also nightly)
$DC exec mysql mysql -uroot -p ntrec     # database shell
$DC --profile clamav up -d clamav        # enable virus scanning (then CLAMAV_HOST=clamav)
```

### Where data lives

| Volume | Contents | Back up? |
|---|---|---|
| `ntrec_storage` | applicant documents, generated PDFs, logs | **yes** (file-level) |
| `ntrec_backups` | nightly verified database dumps | **yes, copy off-host** |
| `ntrec_mysql-data` | the live database | via the dumps above |
| `ntrec_redis-data` | queue and cache | no |

Copy the backups and storage volumes off the server, for example:

```bash
docker run --rm -v ntrec_backups:/b:ro -v ntrec_storage:/s:ro -v /mnt/offsite:/out alpine \
    sh -c 'tar czf /out/ntrec-$(date +%F).tgz -C / b s'
```

### Restore (Docker)

```bash
DC="docker compose -f compose.prod.yaml"
$DC stop app queue scheduler web
$DC exec app ls -t /var/backups/ntrec          # pick a dump (or use an off-site copy)
$DC exec -T app cat /var/backups/ntrec/<file>.sql.gz | gunzip | $DC exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" ntrec'
$DC up -d
```

Restore the `ntrec_storage` volume from the off-site copy taken at the same time. See
[OPERATIONS.md](OPERATIONS.md#recovery) for what to verify afterwards. This procedure was
rehearsed on a staging stack: every table matched the live database except audit entries
written after the dump was taken, as expected.

### Running a staging copy on the same host

```bash
ENV_FILE=/srv/ntrec-staging/.env PROJECT=ntrec-staging ./scripts/deploy.sh
```

The staging `.env` must set different `HTTP_PORT`/`HTTPS_PORT` and
`APP_ENV_FILE=/srv/ntrec-staging/.env`. Add `--profile staging` to catch all mail in Mailpit
(`MAIL_HOST=mailpit`, `MAIL_PORT=1025`).

---

## B. Native install

## 1. Server packages

```bash
sudo apt install nginx mysql-server redis-server supervisor unzip git \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis
# Composer: https://getcomposer.org/download/   Node.js 22 LTS (build step only; Vite 7 needs 22.12+)
```

Optional: `clamav-daemon` for upload virus scanning (set `CLAMAV_HOST=127.0.0.1`).

## 2. Database

```sql
CREATE DATABASE ntrec CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ntrec'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON ntrec.* TO 'ntrec'@'localhost';
-- for db:backup routines/triggers dump:
GRANT PROCESS ON *.* TO 'ntrec'@'localhost';
```

Keep MySQL's default `sql_mode` (it includes `ONLY_FULL_GROUP_BY`, which the application is
written for). No MySQL `time_zone` change is needed; the application stores timestamps in
`APP_TIMEZONE` (default `Asia/Kolkata`).

## 3. Application

```bash
sudo mkdir -p /var/www/ntrec && sudo chown deploy:www-data /var/www/ntrec
cd /var/www/ntrec
git clone <repository-url> .
composer install --no-dev --optimize-autoloader
npm ci && npm run build && rm -rf node_modules      # build assets, then drop node_modules
cp .env.example .env
php artisan key:generate
```

Edit `.env` (see [OPERATIONS.md](OPERATIONS.md#environment-reference)): at minimum
`APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, `DB_*`, `MAIL_*`, `REDIS_*`, `BACKUP_PATH`.
Keep `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`.

```bash
php artisan migrate --force --seed          # schema + reference data (no demo data)
php artisan recruitment:create-admin registrar@iiitm.ac.in "Registrar" --designation=Registrar
php artisan storage:link                    # public disk only; applicant files stay private
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache

sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo chown deploy:www-data .env && sudo chmod 640 .env     # PHP-FPM (www-data) must be able to read it
```

`storage/app/private` holds every applicant document and generated PDF. It must never be
reachable over HTTP; the Nginx configuration below only serves `public/`.

## 4. Nginx with HTTPS

```nginx
server {
    listen 80;
    server_name recruitment.iiitm.ac.in;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name recruitment.iiitm.ac.in;
    root /var/www/ntrec/public;
    index index.php;

    ssl_certificate     /etc/ssl/iiitm/fullchain.pem;
    ssl_certificate_key /etc/ssl/iiitm/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    client_max_body_size 25m;          # largest document type is 20 MB
    server_tokens off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* \.(?:js|css|svg|woff2?)$ {
        expires 30d;
        access_log off;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

The application itself sends `Strict-Transport-Security`, `X-Frame-Options`,
`X-Content-Type-Options`, `Referrer-Policy: same-origin` (referee tokens are in URLs; never sent to other sites) and, in
production, a `Content-Security-Policy`. If TLS terminates at a load balancer, set
`TRUSTED_PROXIES` to its address and `FORCE_HTTPS=true`.

PHP: in `/etc/php/8.3/fpm/php.ini` set `upload_max_filesize = 20M`, `post_max_size = 25M`,
`expose_php = Off`, `session.cookie_secure = 1`.

## 5. Queue worker (Supervisor)

All email — including referee invitations — goes through the queue. Without a worker no
email is sent (the email log will show messages stuck at **Queued**).

`/etc/supervisor/conf.d/ntrec-worker.conf`:

```ini
[program:ntrec-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ntrec/artisan queue:work redis --queue=mail,default --sleep=3 --tries=5 --max-time=3600
user=deploy
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/www/ntrec/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start ntrec-worker:*
```

Each email job retries up to 5 times with back-off (30 s, 2 min, 10 min, 30 min). After the
last failure it is marked **Failed** in the email log; failed referee invitations alert the
Recruitment Administrators by email and in-app.

## 6. Scheduler (cron)

```cron
* * * * * deploy cd /var/www/ntrec && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks (`routes/console.php`):

| Task | When |
|---|---|
| `referees:send-reminders` — reminders per each advertisement's schedule | hourly |
| `advertisements:close-expired` — mark advertisements past their closing time as closed | every 15 min |
| `applications:deadline-reminders --days=3` — remind candidates with unsubmitted drafts | daily 09:00 |
| `db:backup --prune` — verified compressed MySQL dump | daily 01:30 |
| `recruitment:retention-review` — archive and flag advertisements past their retention period (deletes nothing) | monthly, 1st 02:00 |
| `queue:prune-failed --hours=720`, `auth:clear-resets` | daily / 15 min |

## 7. Deploying updates

```bash
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build && rm -rf node_modules
php artisan db:backup                       # always back up before migrating
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart                   # workers pick up the new code
php artisan up
```

## 7a. Troubleshooting sign-in

**"Sign-in is not available from this address"** (HTTP 419 on `POST /api/auth/login`), or in
older builds `Session store not set on request` in `storage/logs/laravel.log`: Sanctum starts a
session only for requests whose `Origin`/`Referer` host is listed in `SANCTUM_STATEFUL_DOMAINS`.
Set it to exactly what users type in the address bar — host plus port, no scheme, several values
comma-separated (`recruitment.iiitm.ac.in,10.10.1.20:8443`) — then rebuild the cache:

```bash
php artisan config:clear && php artisan config:cache && sudo systemctl reload php8.3-fpm
php artisan recruitment:preflight --production     # checks APP_URL's host is in the list
```

If the request carries neither header, look for a `Referrer-Policy: no-referrer` line added to the
Nginx site and remove it (the application sends `same-origin`, which Sanctum needs). Over plain
`http://` the session cookie is dropped by the browser (`SESSION_SECURE_COOKIE=true`): use HTTPS,
or for a short LAN test set `SESSION_SECURE_COOKIE=false` and `FORCE_HTTPS=false`.

## 8. Go-live checklist

```bash
php artisan recruitment:preflight --production
```

- [ ] Preflight passes.
- [ ] Demo data not loaded (or `migrate:fresh --seed` run without `DemoSeeder`).
- [ ] A test email reaches an external mailbox (send a password reset to yourself); SPF/DKIM
      configured for the sender domain so referee invitations are not marked as spam.
- [ ] Queue worker and cron running; email log shows **Sent** for the test email.
- [ ] Backups land in `BACKUP_PATH` and are copied off the server (see OPERATIONS.md).
- [ ] Privacy notice, declaration text and institute contact details reviewed under **Settings**.
- [ ] Roles & permissions reviewed by the Super Administrator.
- [ ] HTTPS certificate valid; HTTP redirects to HTTPS.
