# Live WhatsApp + pre-screening logs (Windows local)
param([ValidateSet('whatsapp','prescreening','all')][string]$Mode = 'all')
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root
$Day = Get-Date -Format 'yyyy-MM-dd'

function Get-LogPath([string]$Name) {
    $daily = Join-Path $Root "storage\logs\$Name-$Day.log"
    $single = Join-Path $Root "storage\logs\$Name.log"
    if (Test-Path $daily) { return $daily }
    if (Test-Path $single) { return $single }
    return $null
}

switch ($Mode) {
    'whatsapp' { Get-Content (Get-LogPath 'whatsapp') -Wait -Tail 50 }
    'prescreening' { Get-Content (Get-LogPath 'prescreening') -Wait -Tail 50 }
    'all' {
        $w = Get-LogPath 'whatsapp'
        $p = Get-LogPath 'prescreening'
        Write-Host "WhatsApp: $w"
        Write-Host "Pre-screening: $p"
        Get-Content $w, $p -Wait -Tail 30 -ErrorAction SilentlyContinue
    }
}
