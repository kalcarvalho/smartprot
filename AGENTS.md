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
- `POST /devices/register`, `POST /devices/{device:public_id}/heartbeat`, `GET /devices/{device:public_id}/policy`, `POST /devices/{device:public_id}/events`, `POST /devices/{device:public_id}/domains` (client reports observed domains)

Web panel routes in `routes/web.php`:
- `/dashboard`, `/login`, `/devices`, `/devices/{device}/events`, `/devices/{device}/claim`, `/devices/{device}/protection`, `/devices/{device}/rules` (CRUD), `/devices/{device}/domains` (observed domains: block or associate with an app), `/app-domain-mappings` (global CRUD), `/profile`

Two **separate** `DeviceController` classes:
- `App\Http\Controllers\Api\V1\DeviceController` — device-to-server API (Bearer token auth, SHA-256 hash comparison via `hash_equals`)
- `App\Http\Controllers\Web\DeviceController` — parent panel CRUD (session auth, Laravel resource controller pattern)

Device model uses route key binding on `public_id` column (not the default `id`).

## Domain model

- `Device` — owned by `User` (FK `user_id`), has many `Policy`, `DeviceEvent`, `DeviceDomain`
- `Policy` — versioned JSON `rules` column, unique per `[device_id, version]`
- `DeviceEvent` — `type`, `payload` (JSON), `occurred_at`
- `DeviceDomain` — per-device observed domain log (`domain`, `app_package` nullable, `seen_count`, `first_seen`, `last_seen`), unique per `[device_id, domain]`
- `AppDomainMapping` — global `app_package` → `domain` table, returned to clients as `app_domains` in the policy response; rows come from the seeder or from a guardian associating an observed domain with an app in the panel

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

## VPN & Traffic Blocking Architecture

```
[Dial up] VpnService.Builder
  .addRoute("0.0.0.0", 0)          # all traffic → TUN
  .addDisallowedApplication(pkg)   # SmartProt bypasses VPN (loop prevention)
  → tun0 interface, TUN forwarder daemon
```

### TUN Forwarder (`TunForwarder.kt`)

- Reads IP packets from TUN fd in daemon thread
- Handles TCP (proto 6) and UDP (proto 17) only
- **TCP**: SYN → real socket connect (Network.bindSocket), data relay bidirecional via out-thread
- **UDP**: DatagramSocket forward, per-destination-pair caching
- **Observação é desacoplada de bloqueio**: o domínio (Host/SNI/DNS) é sempre extraído e reportado via `onDomainObserved`, mesmo quando nada está bloqueado; só a decisão de bloquear (RST/NXDOMAIN) depende da blocklist atual. Isso é o que permite a tela de "domínios observados" no painel.
- **DNS blocking** (UDP:53): extrai o nome de domínio da query DNS e retorna NXDOMAIN se estiver na blocklist
- **HTTP blocking** (porta 80): inspeciona header `Host:` no primeiro pacote de dados TCP
- **HTTPS blocking** (porta 443): extrai SNI do TLS ClientHello e bloqueia se na blocklist
- **IP blocking**: SYN com IP destino na blocklist recebe RST imediato

### Policy Rule Expansion (`PolicyVpnService.kt`)

- Regras `type: "app"` são expandidas dinamicamente usando `app_domains` (vindo do servidor na resposta de `/policy`, tabela `AppDomainMapping`) combinado com `KNOWN_APP_DOMAINS`, que agora é só um baseline offline para o primeiro uso antes de sincronizar — novos apps devem ser cadastrados via o painel ou o seeder, não no map hardcoded
- Domínios observados são bufferizados (`observedDomains`) e enviados periodicamente para `POST /devices/{id}/domains`, atribuídos a um app via `ConnectivityManager.getConnectionOwnerUid` (API 29+)
- `blockedAppPackages` state flow exposto para UI
- `applyRules()` reconstrói o VPN (chama `builder.establish()` novamente) para aplicar regras sem reiniciar o service

### Limitações conhecidas

- **Chrome/Google Play emulator bypass**: Chrome no emulador API 36 solicita rede `NOT_VPN` ativamente e bypassa o TUN. Em dispositivos reais, apps comuns não fazem isso.
- **DNS system bypass**: Android 10+ usa resolvedor de DNS interno que não passa UDP:53 pelo TUN. Contornado via SNI/Host inspection.
- **TCP state**: O forwarder não limpa conexões órfãs (pode acumular entradas no ConcurrentHashMap).
- **Emulator Google Play** intercepta portas 8000/8080/8090 — Docker mapeado para `8081:8000`.
