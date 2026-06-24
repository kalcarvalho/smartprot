# SmartProt

SmartProt is a parental-control style application for managing internet access on client smartphones. The current backend is a Laravel 12 web panel and API with Docker and PostgreSQL.

## Structure

```text
app-cli/      Local tools, ADB scripts, API simulators, and prototypes
docker/       PHP container image files
docs/         Setup, deploy, and feature documentation
specs/        Architecture, API, and policy contracts
web/          Laravel panel and API
```

## Local Development

Docker is the preferred environment.

```powershell
Copy-Item .env.example .env
# Edit .env and set local-only passwords before starting containers.
docker compose up -d --build
docker compose exec app php artisan test
```

The panel runs at `http://localhost:8081` by default. The admin e-mail and password are read from your local `.env` and must not be committed.

## Current Features

- Web login/logout for guardians.
- Guardian profile update.
- Smartphone registration and pairing token generation.
- Versioned blocking rules by app, domain, URL, or IP.
- Device API for registration, heartbeat, policy sync, and event reporting.