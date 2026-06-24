#!/bin/sh
set -eu

mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs
chmod -R 775 bootstrap/cache storage

cat > .env <<EOF
APP_NAME=SmartProt
APP_ENV=production
APP_KEY=
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
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi
php artisan migrate --force
php artisan db:seed --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
exec php artisan serve --host=0.0.0.0 --port=8000