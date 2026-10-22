# syntax=docker/dockerfile:1
#
# Production image for the ABV-IIITM Non-Teaching Recruitment Portal.
# Immutable: application code, PHP dependencies and built assets are baked in.
# Configuration comes from .env mounted at runtime; documents live on a volume.
#
#   docker compose -f compose.prod.yaml build
#
# Targets: `app` (PHP-FPM; also runs the queue worker and scheduler) and `web` (Nginx).

# --- 1. Front-end assets ------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# --- 2. PHP dependencies (no dev packages) -------------------------------------
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --- 3. Runtime ---------------------------------------------------------------
FROM php:8.3-fpm-bookworm AS app

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql bcmath gd intl zip exif opcache pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client gzip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-ntrec.ini
COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data --from=vendor /build /var/www/html
COPY --chown=www-data:www-data --from=assets /build/public/build /var/www/html/public/build
RUN rm -f public/hot \
    && mkdir -p storage/app/private storage/app/backups storage/framework/cache/data storage/framework/sessions \
                storage/framework/views storage/logs storage/fonts bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && mkdir -p /var/backups/ntrec && chown www-data:www-data /var/backups/ntrec && chmod 700 /var/backups/ntrec

USER www-data
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- 4. Web server ------------------------------------------------------------
# Nginx sees only public/ (static files and the front controller), never app code or storage.
FROM nginx:1.27-alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/prod/nginx/ /etc/nginx/ntrec/
RUN rm /etc/nginx/conf.d/default.conf
