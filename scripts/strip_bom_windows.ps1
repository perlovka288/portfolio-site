<#
Strip UTF-8 BOM (0xEF 0xBB 0xBF) and leading whitespace/newlines before <?php
Usage:
  # Process only admin folder (recommended)
  powershell -ExecutionPolicy Bypass -File .\scripts\strip_bom_windows.ps1 -Path .\admin -Recurse -RunPhpCheck $true

  # Process whole project (may change many files)
  powershell -ExecutionPolicy Bypass -File .\scripts\strip_bom_windows.ps1 -Path . -Recurse -RunPhpCheck $false
#>

param(
    [Parameter(Mandatory=$false)]
    [string]$Path = ".",
    [Parameter(Mandatory=$false)]
    [switch]$Recurse = $true,
    [Parameter(Mandatory=$false)]
    [switch]$RunPhpCheck = $false
)

Write-Host "Strip BOM script starting. Target path: $Path" -ForegroundColor Cyan
# collect files
$pattern = @('*.php','*.phtml','*.inc')
$files = @()
foreach ($pat in $pattern) {
    if ($Recurse) { $files += Get-ChildItem -Path $Path -Filter $pat -Recurse -File -ErrorAction SilentlyContinue }
    else { $files += Get-ChildItem -Path $Path -Filter $pat -File -ErrorAction SilentlyContinue }
}

$files = $files | Sort-Object -Property FullName -Unique
if ($files.Count -eq 0) { Write-Host "No PHP files found under $Path"; exit 0 }

$modified = @()
foreach ($f in $files) {
    $p = $f.FullName
    try {
        $bytes = [System.IO.File]::ReadAllBytes($p)
        if ($bytes.Length -eq 0) { continue }
        $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
        if ($hasBom) {
            $text = [System.Text.Encoding]::UTF8.GetString($bytes, 3, $bytes.Length - 3)
        } else {
            $text = [System.Text.Encoding]::UTF8.GetString($bytes)
        }
        # remove leading whitespace/newlines before <?php
        $text2 = [regex]::Replace($text, '^[\r\n\s]*?(<\?php)', '$1', 'Singleline')
        if ($text2 -ne $text) {
            $text = $text2
        }
        # write back UTF8 without BOM
        $enc = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($p, $text, $enc)
        if ($hasBom -or $text2 -ne $null) { $modified += $p; Write-Host "Fixed: $p" }
    } catch {
        Write-Warning "Failed to process $p : $_"
    }
}

Write-Host "Done. Files modified: $($modified.Count)" -ForegroundColor Green
if ($modified.Count -gt 0 -and $RunPhpCheck) {
    Write-Host "Running php -l on modified files (if php in PATH)..." -ForegroundColor Cyan
    foreach ($m in $modified) {
        try {
            $res = & php -l $m 2>&1
            Write-Host "php -l $m -> $res"
        } catch {
            Write-Warning "php not found or php -l failed for $m"
            break
        }
    }
}

Write-Host "If web server caches files, please restart Apache/IIS/PHP-FPM." -ForegroundColor Yellow
Write-Host "Finished." -ForegroundColor Cyan
