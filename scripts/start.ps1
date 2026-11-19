$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

if (Test-Path "$Root\config.env") {
    Get-Content "$Root\config.env" | ForEach-Object {
        $line = $_.Trim()
        if (-not $line -or $line.StartsWith('#') -or -not $line.Contains('=')) { return }
        $parts = $line.Split('=', 2)
        $key = $parts[0].Trim()
        $value = $parts[1].Trim().Trim('"').Trim("'")
        if ($key) { Set-Item -Path "Env:$key" -Value $value }
    }
}

$port = if ($env:PORT) { $env:PORT } else { '5400' }
$hostAddr = if ($env:HOST) { $env:HOST } else { '0.0.0.0' }

Write-Host "Starting Pixiv Animation on http://127.0.0.1:$port"
php -S "$hostAddr`:$port" "$Root\router.php"
