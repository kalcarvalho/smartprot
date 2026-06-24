# WSL Deploy Copy

A deploy-oriented copy of SmartProt lives at:

```bash
/home/kalcarvalho/smartprot
```

Use this WSL directory for SSH-based deployment tasks. Keep day-to-day Codex edits in `C:\Dev\smartprot`, then sync to WSL when needed.

## Sync From Windows

From PowerShell:

```powershell
cd C:\Dev\smartprot
.\sync-wsl-deploy.ps1
```

The sync excludes local dependencies, SQLite files, `.env`, Windows PHP runtimes, and test caches.

## Validate In WSL

```bash
cd /home/kalcarvalho/smartprot/web
composer install
php artisan test
```

The current Ubuntu 24.04 environment has PHP 8.3 and Composer available. Production should use PHP 8.3+ and PostgreSQL.

## Deploy Direction

For server deployment, prefer pulling from Git on the server or rsyncing from this WSL folder over SSH. Do not deploy Windows-only files from `tools/php-*`.
