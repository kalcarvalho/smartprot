# Linux Server Notes

SmartProt targets PHP 8.3 or newer and should run on a standard Linux Laravel stack.

## Required PHP Extensions

Install PHP with these extensions enabled:

- `openssl`
- `pdo`
- database driver: `pdo_mysql` or `pdo_pgsql`
- `mbstring`
- `fileinfo`
- `tokenizer`
- `xml`
- `curl`
- `zip`

SQLite is used for local development only. Production should use MySQL or PostgreSQL.

## Deployment Commands

```bash
cd /var/www/smartprot/web
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

Configure the web server document root to `web/public`.
