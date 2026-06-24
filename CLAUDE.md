# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project layout

- `web/` — Laravel 12 monolith: parent control panel + `/api/v1` consumed by client phones
- `app-cli/smartprot-android/` — Android client (Kotlin, `VpnService`, Retrofit + WorkManager), package `com.sysfactor.apps.smartprot`
- `specs/` — pre-implementation contracts (architecture, API, policy model)
- `docs/` — setup/deploy notes
- `docker/` — container config (PHP 8.3-cli + PostgreSQL 16 Alpine)

## Dev commands

```powershell
# Preferred — Docker (always matches production)
cd C:\Dev\smartprot
docker compose up -d --build          # starts app + postgres, runs migrate + db:seed automatically
docker compose exec app php artisan test
docker compose exec app php artisan migrate:fresh --seed
docker compose down

# Quick native checks on Windows (no DB needed — tests force SQLite in-memory)
cd web
..\tools\php-8.3.31\php.exe artisan test
```

Run a single test:

```powershell
docker compose exec app php artisan test --filter=test_method_name
docker compose exec app php artisan test tests/Feature/DeviceApiTest.php
```

App at `http://localhost:8081` by default (`SMARTPROT_APP_PORT` in `.env`). Admin login comes from `SMARTPROT_ADMIN_EMAIL`/`SMARTPROT_ADMIN_PASSWORD` in the local root `.env` — never commit it. The Docker `app` service runs `composer install`, generates `APP_KEY` if missing, migrates, and seeds on every `up`.

## Testing

Tests **always run against SQLite in memory** (`phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) regardless of Docker's PostgreSQL. Feature tests in `tests/Feature/`, unit tests in `tests/Unit/`. Most feature tests use `RefreshDatabase`. `composer test` (or `php artisan test`) runs `artisan config:clear` first per `composer.json` scripts.

## Routes & controllers

API v1 endpoints in `routes/api.php` under `/api/v1`, all device-token authenticated except register:
- `POST /devices/register`, `POST /devices/{device:public_id}/heartbeat`, `GET /devices/{device:public_id}/policy`, `POST /devices/{device:public_id}/events`, `POST /devices/{device:public_id}/domains`

Web panel routes in `routes/web.php`: `/dashboard`, `/login`, `/devices`, `/devices/{device}/events`, `/devices/{device}/claim`, `/devices/{device}/protection` (toggle), `/devices/{device}/rules` (CRUD), `/devices/{device}/domains` (list observed domains + associate one with an app via `Web\DeviceDomainController`), `/app-domain-mappings` (global CRUD for `AppDomainMapping` via `Web\AppDomainMappingController`), `/profile`.

Two **separate** `DeviceController` classes — don't confuse them:
- `App\Http\Controllers\Api\V1\DeviceController` — device-to-server API. Bearer token auth, SHA-256 hash comparison via `hash_equals` (see private `isAuthorized()` in that file, duplicated per-method rather than middleware).
- `App\Http\Controllers\Web\DeviceController` — parent panel CRUD, session auth, Laravel resource controller pattern. A device with `user_id = null` is unclaimed and visible/claimable by any authenticated guardian (see `claim()`); ownership checks elsewhere are `abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404)`.

`Web\PolicyRuleController` never mutates a `Policy` row in place — every rule add/update/delete/protection-toggle calls `createPolicyVersion()`, which creates a new versioned `Policy` row and bumps `Device.last_policy_version`. Rule shape: `{id (uuid), type, target, network, enabled, schedule, daily_limit_minutes, notes, created_at}`.

Device model uses route key binding on `public_id` (not `id`).

## Domain model

- `Device` — owned by `User` (nullable FK `user_id`, unclaimed until a guardian claims it), has many `Policy`, `DeviceEvent`, `DeviceDomain`
- `Policy` — versioned, unique per `[device_id, version]`; `rules` (JSON array) and `settings` (JSON, currently just `protection_enabled`) are separate columns
- `DeviceEvent` — `type`, `payload` (JSON), `occurred_at` — client-reported telemetry (e.g. `policy_applied`)
- `DeviceDomain` — per-device domain visit log from the client's traffic inspection, unique per `[device_id, domain]`, increments `seen_count`
- `AppDomainMapping` — global `app_package` → `domain` table (not per-device) used to expand `type: "app"` policy rules into the concrete domains an app talks to; returned to the client as `app_domains` in the policy response (grouped in PHP, not via a DB-specific aggregate like Postgres' `json_agg`, so it behaves the same under SQLite tests and Postgres) so the VPN can block an app by blocking its known domains. Rows come either from `AppDomainMappingSeeder` or dynamically: a guardian associating an observed `DeviceDomain` with an app via `Web\DeviceDomainController::associate` upserts one here, which every device then picks up on its next policy sync — no client update needed for new apps.

Policy rule `type`: `app`, `domain`, `url`, `ip`; `network` (action): `blocked`, `allowed`.

## Conventions

- Locale: `pt_BR` (`APP_LOCALE=pt_BR`, `APP_FAKER_LOCALE=pt_BR`). UI strings and flash messages are in Brazilian Portuguese; code/comments are mixed PT/EN.
- Session, cache, queue use the `database` driver in Docker (`array`/`sync` in tests).
- Pairing tokens: returned as plain text once at creation, stored only as a SHA-256 hash (`token_hash`). Never log or persist the plain token server-side.
- Indentation: 4-space PHP, 2-space JSON/YAML/MD/frontend (`.editorconfig`).

## VPN & traffic-blocking architecture (Android client)

```
[Dial up] VpnService.Builder
  .addRoute("0.0.0.0", 0)          # all traffic → TUN
  .addDisallowedApplication(pkg)   # SmartProt bypasses VPN (loop prevention)
  → tun0 interface, TUN forwarder daemon
```

`PolicySyncWorker` and `HeartbeatWorker` (WorkManager, periodic, network-constrained) poll `GET /policy` and `POST /heartbeat` respectively; `BootReceiver` re-enqueues both and restarts the VPN service on device boot if it was previously active. `PolicyVpnService.applyRules()` expands `type: "app"` rules into domains using the server's `app_domains` map from the policy response (the dynamic, primary source — kept in `serverAppDomains`) merged with `KNOWN_APP_DOMAINS`, a small hardcoded baseline kept only for offline/first-run use before any policy has synced. New apps should be added via `AppDomainMapping` on the server (seeder or panel), not the baseline map, so blocking stays dynamic. `PolicySyncWorker`/`MainActivity` pass `policy.appDomains` to `PolicyVpnService` via the `EXTRA_APP_DOMAINS_JSON` intent extra alongside `EXTRA_RULES_JSON`. The expansion result is cached in `resolvedBlockedDomains`/`resolvedBlockedIps` and reused by `startForwarder()` on every VPN rebuild (rebuilding from `currentRules` alone would drop the app→domain expansion).

`PolicyVpnService` also runs a domain-observation loop, independent of blocking: `TunForwarder` reports every domain it sees (HTTP Host / TLS SNI / DNS query) via an `onDomainObserved` callback regardless of whether that domain is currently blocked, so the parent panel can show what a device is trying to reach even when nothing is blocked yet. Observed domains are buffered in `observedDomains` (`ConcurrentHashMap<domain, appPackage?>`, attributed via `ConnectivityManager.getConnectionOwnerUid` — API 29+, falls back to unattributed on the project's `minSdk 28`) and flushed every `DOMAIN_REPORT_INTERVAL_MS` (60s) to `POST /devices/{device}/domains`, which upserts into `DeviceDomain` (see `devices/domains` panel route above).

### TUN Forwarder (`TunForwarder.kt`)

- Reads IP packets from the TUN fd in a daemon thread; handles TCP (proto 6) and UDP (proto 17) only.
- **TCP**: SYN → real socket connect (`Network.bindSocket`), bidirectional data relay via an out-thread.
- **UDP**: `DatagramSocket` forward, per-destination-pair caching.
- **Domain observation vs. blocking are decoupled**: `inspectAndBlock`/`handleDnsQuery` always extract and report the domain (HTTP Host / TLS SNI / DNS query name) via `onDomainObserved`; only the block/RST/NXDOMAIN decision is gated on `currentBlockedDomains`. Don't reintroduce an early `if (currentBlockedDomains.isEmpty()) return` guard around the extraction itself — that's what silently broke per-app blocking for apps missing from the (now-legacy) hardcoded domain list.
- **DNS blocking** (UDP:53): extracts the domain from the DNS query, returns NXDOMAIN if blocklisted.
- **HTTP blocking** (port 80): inspects the `Host:` header in the first TCP data packet.
- **HTTPS blocking** (port 443): extracts SNI from the TLS ClientHello, blocks if blocklisted.
- **IP blocking**: SYN to a blocklisted destination IP gets an immediate RST.

### Known limitations

- Chrome / Google Play emulator (API 36) requests a `NOT_VPN` network and bypasses the TUN; real devices and ordinary apps don't do this.
- Android 10+'s internal DNS resolver doesn't route UDP:53 through the TUN — worked around via SNI/Host inspection instead of DNS blocking alone.
- The TCP forwarder doesn't clean up orphaned connections (can accumulate entries in its `ConcurrentHashMap`).
- Emulator Google Play intercepts ports 8000/8080/8090 — Docker maps `8081:8000` to avoid the collision.

## Notable quirks

- `user_id` FK on `devices` is nullable and was added as a separate migration (`...000004`) after the initial table.
- WSL sync available via `sync-wsl-deploy.ps1` in the project root — see `docs/wsl-deploy-copy.md` for the deploy-vs-dev directory split.
