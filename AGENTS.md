# SmartProt — AGENTS.md

## Project layout

- `web/` — Laravel 12 monolith: parent control panel + `/api/v1` consumed by client phones
- `app-cli/smartprot-android/` — Android client (Kotlin, VPNService, Retrofit + WorkManager)
- `specs/` — pre-implementation contracts (architecture, API, policy model)
- `docs/` — setup/deploy notes
- `docker/` — container config (PHP 8.3-cli + PostgreSQL 16 Alpine)

## Dev commands

```powershell
# Preferred — Docker (always matches production)
cd C:\Dev\smartprot
docker compose up -d --build          # starts app + postgres
docker compose exec app php artisan test
docker compose exec app php artisan migrate:fresh --seed
docker compose down

# Quick native checks on Windows (no DB, tests only)
cd web
..\tools\php-8.3.31\php.exe artisan test
```

App at `http://localhost:8081` by default. Admin login is created from the local root `.env` values and must not be committed.

## Testing

Tests **always run against SQLite in memory** (`phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) regardless of Docker's PostgreSQL. Feature tests in `tests/Feature/`, unit tests in `tests/Unit/`. Most feature tests use `RefreshDatabase`. Run via `php artisan test` (this runs `config:clear` first per `composer.json` scripts).

## Routes & controllers

API v1 endpoints are in `routes/api.php` under `/api/v1`:
- `POST /devices/register`, `POST /devices/{device:public_id}/heartbeat`, `GET /devices/{device:public_id}/policy`, `POST /devices/{device:public_id}/events`

Web panel routes in `routes/web.php`:
- `/dashboard`, `/login`, `/devices`, `/profile`

Two **separate** `DeviceController` classes:
- `App\Http\Controllers\Api\V1\DeviceController` — device-to-server API (Bearer token auth, SHA-256 hash comparison via `hash_equals`)
- `App\Http\Controllers\Web\DeviceController` — parent panel CRUD (session auth, Laravel resource controller pattern)

Device model uses route key binding on `public_id` column (not the default `id`).

## Domain model

- `Device` — owned by `User` (FK `user_id`), has many `Policy` and `DeviceEvent`
- `Policy` — versioned JSON `rules` column, unique per `[device_id, version]`
- `DeviceEvent` — `type`, `payload` (JSON), `occurred_at`

## Conventions

- Locale: `pt_BR` (`APP_LOCALE=pt_BR`, `APP_FAKER_LOCALE=pt_BR`). UI strings are in Brazilian Portuguese.
- Session, cache, queue all use `database` driver by default.
- Pairing tokens: returned as plain text once at creation, stored as SHA-256 hash.
- Policy rules are JSON — supported types: `app`, `domain`, `url`, `ip`; actions: `blocked`, `allowed`.
- Indentation: 4-space PHP, 2-space JSON/YAML/MD/frontend.

## Notable quirks

- `user_id` FK on `devices` is nullable and was added as a separate migration (`0004`) after the initial table.
- No Git history yet — commit imperatively.
- WSL sync available via `sync-wsl-deploy.ps1` in project root.
