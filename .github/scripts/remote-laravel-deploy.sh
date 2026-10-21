#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${DEPLOY_ROOT:?DEPLOY_ROOT is required}"
[ -d "${APP_DIR}" ] || { echo "ERROR: App directory does not exist: ${APP_DIR}"; exit 1; }

echo "[remote] Running deploy commands in: ${APP_DIR}"
cd "${APP_DIR}"

# Hostinger SSH defaults to an older PHP CLI; use the site's PHP 8.3+ binary.
PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
  for v in 83 84 85; do
    if [ -x "/opt/alt/php${v}/usr/bin/php" ]; then PHP_BIN="/opt/alt/php${v}/usr/bin/php"; break; fi
  done
fi
PHP_BIN="${PHP_BIN:-php}"
echo "[php] using $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"

if command -v composer >/dev/null 2>&1 && [ -f composer.lock ]; then
  LOCK_HASH_FILE="${APP_DIR}/.deploy-composer-lock-hash"
  CURRENT_HASH="$(md5sum composer.lock 2>/dev/null | awk '{print $1}' || true)"
  if [ -n "${CURRENT_HASH}" ] && [ -d vendor ] && [ -f "${LOCK_HASH_FILE}" ] && [ "$(cat "${LOCK_HASH_FILE}")" = "${CURRENT_HASH}" ]; then
    echo "[composer] composer.lock unchanged, skipping install"
  else
    echo "[composer] installing dependencies..."
    "$PHP_BIN" "$(command -v composer)" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    [ -n "${CURRENT_HASH}" ] && echo "${CURRENT_HASH}" > "${LOCK_HASH_FILE}"
  fi
else
  echo "ERROR: composer not found on server"; exit 1
fi

if [ -f artisan ]; then
  [ -f .env ] || { echo "ERROR: ${APP_DIR}/.env is missing."; exit 1; }

  echo "[artisan] linking public storage..."
  "$PHP_BIN" artisan storage:link 2>/dev/null || true
  mkdir -p public/storage
  if [ -d storage/app/public ]; then
    rsync -a storage/app/public/ public/storage/ 2>/dev/null || cp -R storage/app/public/. public/storage/ 2>/dev/null || true
  fi

  echo "[artisan] fixing permissions..."
  mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
  chmod -R 775 storage bootstrap/cache 2>/dev/null || true

  "$PHP_BIN" artisan optimize:clear
  "$PHP_BIN" artisan migrate --force
  "$PHP_BIN" artisan config:cache
  "$PHP_BIN" artisan route:clear
  "$PHP_BIN" artisan view:cache
fi

echo "[remote] Deploy commands completed successfully"
