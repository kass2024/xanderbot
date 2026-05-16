# Copy Linux VPS .env.linux → .env (for upload prep on Windows)
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$src = Join-Path $root '.env.linux'
$dest = Join-Path $root '.env'
if (-not (Test-Path $src)) {
    Write-Error 'Missing .env.linux in ' $root
    exit 1
}
Copy-Item -Path $src -Destination $dest -Force
Write-Host 'Copied .env.linux → .env (production). Upload .env to VPS, then:'
Write-Host '  php artisan config:clear && php artisan config:cache'
