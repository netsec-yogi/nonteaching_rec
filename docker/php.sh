#!/usr/bin/env bash
# Runs php/composer/artisan inside the dev container against the project dir.
exec docker run --rm -t \
  --user "$(id -u):$(id -g)" -e HOME=/tmp \
  --network ntrec-net \
  -v "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)":/app \
  -v /tmp/ntrec-composer:/tmp/composer \
  -w /app "${NTREC_PHP_IMAGE:-abvrec-php:8.3}" "$@"
