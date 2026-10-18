#!/usr/bin/env bash
# Release-directory deploy: build the new release, migrate, switch the symlink,
# then restart workers gracefully. Rollback = point `current` at the previous release.
set -euo pipefail

APP_ROOT=/var/www/whatsapp-workspace
REPO=${REPO:?set REPO (git URL)}
REF=${REF:-main}
RELEASE="$APP_ROOT/releases/$(date +%Y%m%d%H%M%S)"

git clone --depth 1 --branch "$REF" "$REPO" "$RELEASE"
cd "$RELEASE"

ln -sfn "$APP_ROOT/shared/.env" .env
rm -rf storage && ln -sfn "$APP_ROOT/shared/storage" storage
ln -sfn "$APP_ROOT/shared/gateway/.env" services/whatsapp-gateway/.env

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci && npm run build && rm -rf node_modules
(cd services/whatsapp-gateway && npm ci && npm run build && npm prune --omit=dev)

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

ln -sfn "$RELEASE" "$APP_ROOT/current"
sudo systemctl reload php8.4-fpm

php artisan horizon:terminate          # supervisor restarts it on the new code
php artisan reverb:restart             # graceful: finishes open connections
sudo supervisorctl restart whatsapp-workspace:ww-gateway   # SIGTERM → sessions closed, restored on start

# Keep the five most recent releases.
ls -1dt "$APP_ROOT"/releases/* | tail -n +6 | xargs -r rm -rf
echo "Deployed $REF to $RELEASE"
