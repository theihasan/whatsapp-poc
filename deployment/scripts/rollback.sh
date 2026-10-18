#!/usr/bin/env bash
# Point `current` at the previous release and restart workers. Database migrations are
# NOT rolled back automatically — ship forward-fix migrations instead (docs/deployment.md).
set -euo pipefail
APP_ROOT=/var/www/whatsapp-workspace
PREVIOUS=$(ls -1dt "$APP_ROOT"/releases/* | sed -n 2p)
[ -n "$PREVIOUS" ] || { echo "No previous release"; exit 1; }
ln -sfn "$PREVIOUS" "$APP_ROOT/current"
cd "$APP_ROOT/current"
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.4-fpm
php artisan horizon:terminate
php artisan reverb:restart
sudo supervisorctl restart whatsapp-workspace:ww-gateway
echo "Rolled back to $PREVIOUS"
