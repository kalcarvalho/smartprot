# Development Environment

SmartProt uses Laravel 12 with PHP 8.3 and PostgreSQL. Docker is the preferred local environment because production will run on Linux.

## Start With Docker

```powershell
cd C:\Dev\smartprot
Copy-Item .env.example .env
# Edit .env with local-only database and admin passwords.
docker compose up --build
```

The Laravel app is available at `http://localhost:8081` by default. Credentials are created from `SMARTPROT_ADMIN_EMAIL` and `SMARTPROT_ADMIN_PASSWORD` in the local `.env` file.

## Useful Docker Commands

```powershell
docker compose exec app php artisan test
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan route:list
docker compose down
```

## Native Windows Runtime

A local PHP 8.3 runtime may be used for quick commands, but it is intentionally ignored by Git:

```powershell
cd C:\Dev\smartprot\web
..\tools\php-8.3.31\php.exe artisan test
```

Docker remains the reference environment for database behavior and Linux compatibility.