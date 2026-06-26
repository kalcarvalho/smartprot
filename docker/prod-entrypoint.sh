#!/bin/sh
set -eu

mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs
chmod -R 775 bootstrap/cache storage

# storage/ lives on the bind-mounted host volume (./web:/var/www/html), so this
# file survives container restarts -- unlike .env itself, which is rewritten
# from scratch below on every boot. Without this, every restart silently
# rotated APP_KEY, invalidating every session/cookie still in use.
KEY_FILE=storage/.app_key
if [ ! -s "$KEY_FILE" ]; then
    php -r "echo 'base64:'.base64_encode(random_bytes(32));" > "$KEY_FILE"
fi
APP_KEY=$(cat "$KEY_FILE")

cat > .env <<EOF
APP_NAME=SmartProt
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=${APP_URL}
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=pt_BR
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
SESSION_DRIVER=database
SESSION_LIFETIME=120
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
SMARTPROT_ADMIN_NAME="SmartProt Admin"
SMARTPROT_ADMIN_EMAIL=${SMARTPROT_ADMIN_EMAIL}
SMARTPROT_ADMIN_PASSWORD=${SMARTPROT_ADMIN_PASSWORD}
EOF

composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
exec php artisan serve --host=0.0.0.0 --port=8000