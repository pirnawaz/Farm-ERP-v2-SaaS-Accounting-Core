# Find PHP Installation Script
Write-Host "Searching for PHP 8.5 installation..." -ForegroundColor Green
Write-Host ""

# Check PATH first
try {
    $phpInPath = (Get-Command php -ErrorAction Stop).Source
    Write-Host "[OK] PHP found in PATH: $phpInPath" -ForegroundColor Green
    & $phpInPath --version | Select-Object -First 1
    exit 0
} catch {
    Write-Host "[INFO] PHP not in PATH, searching D: drive..." -ForegroundColor Yellow
}

# Search common D: locations
$searchPaths = @(
    "D:\php\php-8.5.1-Win32-vs17-x64\php.exe",
    "D:\php\php-8.5.1-src\php-8.5.1-src\php.exe",
    "D:\php\php.exe",
    "D:\php-8.5\php.exe",
    "D:\php-8.5.1\php.exe",
    "D:\php\php-8.5\php.exe",
    "D:\php\php-8.5.1\php.exe"
)

$found = $false
foreach ($path in $searchPaths) {
    if (Test-Path $path) {
        Write-Host "[OK] Found PHP at: $path" -ForegroundColor Green
        & $path --version | Select-Object -First 1
        $found = $true
        break
    }
}

# Recursive search in D:\php if it exists
if (-not $found -and (Test-Path "D:\php")) {
    Write-Host "[INFO] Searching recursively in D:\php..." -ForegroundColor Gray
    $phpFiles = Get-ChildItem "D:\php" -Recurse -Filter "php.exe" -ErrorAction SilentlyContinue | Select-Object -First 3
    if ($phpFiles) {
        foreach ($phpFile in $phpFiles) {
            Write-Host "[OK] Found PHP at: $($phpFile.FullName)" -ForegroundColor Green
            & $phpFile.FullName --version | Select-Object -First 1
            $found = $true
        }
    }
}

if (-not $found) {
    Write-Host ""
    Write-Host "[ERROR] PHP not found!" -ForegroundColor Red
    Write-Host "Please provide the full path to php.exe" -ForegroundColor Yellow
    Write-Host "Example: D:\php\php-8.5.1\php.exe" -ForegroundColor Gray
    exit 1
}

Write-Host ""
Write-Host "To add PHP to PATH, run this command as Administrator:" -ForegroundColor Cyan
Write-Host '  [Environment]::SetEnvironmentVariable("Path", $env:Path + ";D:\php\php-8.5.1", "Machine")' -ForegroundColor White
