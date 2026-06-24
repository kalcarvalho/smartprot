$ErrorActionPreference = "Stop"

$source = "/mnt/c/Dev/smartprot/"
$target = "/home/kalcarvalho/smartprot/"

wsl.exe -d Ubuntu-24.04 -- bash -lc @"
set -euo pipefail
mkdir -p '$target'
rsync -a --delete \
  --exclude '/web/vendor/' \
  --exclude '/web/node_modules/' \
  --exclude '/web/database/*.sqlite' \
  --exclude '/web/.env' \
  --exclude '/web/.phpunit.result.cache' \
  --exclude '/tools/php-*/' \
  --exclude '/tools/*.zip' \
  '$source' '$target'
"@

Write-Host "SmartProt synced to WSL: /home/kalcarvalho/smartprot"
