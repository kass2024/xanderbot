# Restore local Windows .env (from .env.local)
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$src = Join-Path $root '.env.local'
$dest = Join-Path $root '.env'
if (-not (Test-Path $src)) {
    Write-Error "Missing $src — keep .env.local in sync with your local settings."
    exit 1
}
Copy-Item -Path $src -Destination $dest -Force
Write-Host 'Copied .env.local -> .env (APP_ENV=local, XANDER_PHP_PATH set).'
Write-Host 'Run: php artisan config:clear'
Write-Host 'Start: php artisan serve'
