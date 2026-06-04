<#
Post-BOM actions: run php -l on admin files and try to restart common web services (Apache/IIS)
Usage:
  powershell -ExecutionPolicy Bypass -File .\scripts\post_bom_actions.ps1
#>

Write-Host "Post-BOM actions starting..." -ForegroundColor Cyan
$root = Get-Location
$adminIndex = Join-Path $root 'admin\index.php'
$adminAuth  = Join-Path $root 'admin\auth.php'

# find php.exe: try PATH then common install locations
function Find-PHP {
    Write-Host "Looking for php in PATH..." -NoNewline
    $php = (Get-Command php -ErrorAction SilentlyContinue).Path
    if ($php) { Write-Host " found: $php"; return $php }
    Write-Host " not in PATH. Searching common locations..."
    $candidates = @(
        'C:\xampp\php\php.exe',
        'C:\Program Files\PHP\php.exe',
        'C:\Program Files (x86)\PHP\php.exe',
        'C:\php\php.exe'
    )
    foreach ($c in $candidates) { if (Test-Path $c) { Write-Host "Found php: $c"; return $c } }
    return $null
}

$phpExe = Find-PHP
if (-not $phpExe) { Write-Warning "PHP executable not found. Please ensure php is in PATH or update script. Skipping php -l checks." }
else {
    Write-Host "Running php -l on admin files..."
    & $phpExe -l $adminIndex 2>&1 | ForEach-Object { Write-Host $_ }
    & $phpExe -l $adminAuth 2>&1 | ForEach-Object { Write-Host $_ }
}

# Try restart Apache-like services
$apacheServices = Get-Service | Where-Object { $_.Name -match 'apache|httpd|wampapache|xamppapache|Apache2.4' -or $_.DisplayName -match 'Apache|XAMPP' } | Select-Object -Unique
if ($apacheServices.Count -gt 0) {
    Write-Host "Found Apache-like services:" -ForegroundColor Green
    $apacheServices | ForEach-Object { Write-Host " - $($_.Name) ($($_.Status))" }
    foreach ($s in $apacheServices) {
        try {
            Write-Host "Restarting service $($s.Name)..."
            Restart-Service -Name $s.Name -Force -ErrorAction Stop
            Write-Host "Restarted $($s.Name)" -ForegroundColor Green
        } catch { Write-Warning "Failed to restart $($s.Name): $_" }
    }
} else {
    Write-Host "No Apache-like services found. Trying IIS reset..."
    try {
        & iisreset 2>&1 | ForEach-Object { Write-Host $_ }
    } catch {
        Write-Warning "IIS reset failed or iisreset not available. Please restart your web server manually (Apache/XAMPP/IIS)." }
}

Write-Host "Post-BOM actions finished. Check your admin page now." -ForegroundColor Cyan
