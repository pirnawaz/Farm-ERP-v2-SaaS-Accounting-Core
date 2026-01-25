# Start Servers Script for Farm ERP v2
# This script handles path encoding issues

$ErrorActionPreference = "Continue"

# Get the actual project path using the script location
try {
    $scriptFile = Get-Item $MyInvocation.MyCommand.Path -ErrorAction Stop
    $scriptPath = $scriptFile.DirectoryName
    Write-Host "Script location: $scriptPath" -ForegroundColor Gray
} catch {
    # Fallback: try to find the project by looking for apps directory
    $possiblePaths = @(
        "d:\Cursor\Farm ERP v2 – SaaS Accounting Core",
        (Get-Location).Path,
        $PSScriptRoot
    )
    $scriptPath = $null
    foreach ($path in $possiblePaths) {
        if ($path -and (Test-Path (Join-Path $path "apps"))) {
            $scriptPath = $path
            break
        }
    }
    if (-not $scriptPath) {
        Write-Host "ERROR: Cannot determine project path. Please run this script from the project root." -ForegroundColor Red
        exit 1
    }
}

Write-Host "Starting Farm ERP v2 servers..." -ForegroundColor Green
Write-Host "Project path: $scriptPath" -ForegroundColor Cyan

# Check for Node.js
$nodeVersion = node --version 2>$null
if (-not $nodeVersion) {
    Write-Host "ERROR: Node.js is not installed or not in PATH" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Node.js: $nodeVersion" -ForegroundColor Green

# Check for PHP
$phpExe = $null
try {
    $phpExe = (Get-Command php -ErrorAction Stop).Source
    $phpVersion = & $phpExe --version 2>$null | Select-Object -First 1
    Write-Host "✓ PHP found in PATH: $phpVersion" -ForegroundColor Green
} catch {
    # Try to find PHP in common D: drive locations
    Write-Host "PHP not in PATH, searching D: drive..." -ForegroundColor Gray
    $phpSearchPaths = @(
        "D:\php\php-8.5.1-Win32-vs17-x64\php.exe",
        "D:\php\php-8.5.1-src\php-8.5.1-src\php.exe",
        "D:\php\php.exe",
        "D:\php-8.5\php.exe",
        "D:\php-8.5.1\php.exe",
        "D:\php\php-8.5\php.exe",
        "D:\php\php-8.5.1\php.exe"
    )
    
    foreach ($phpPath in $phpSearchPaths) {
        if (Test-Path $phpPath) {
            $phpExe = $phpPath
            $phpVersion = & $phpExe --version 2>$null | Select-Object -First 1
            Write-Host "✓ PHP found at: $phpExe" -ForegroundColor Green
            Write-Host "  Version: $phpVersion" -ForegroundColor Gray
            break
        }
    }
    
    # Also search recursively in D:\php if it exists
    if (-not $phpExe -and (Test-Path "D:\php")) {
        # First check the specific paths
        $specificPaths = @(
            "D:\php\php-8.5.1-Win32-vs17-x64\php.exe",
            "D:\php\php-8.5.1-src\php-8.5.1-src\php.exe"
        )
        $found = $false
        foreach ($specificPath in $specificPaths) {
            if (Test-Path $specificPath) {
                $phpExe = $specificPath
                $phpVersion = & $phpExe --version 2>$null | Select-Object -First 1
                Write-Host "✓ PHP found at: $phpExe" -ForegroundColor Green
                Write-Host "  Version: $phpVersion" -ForegroundColor Gray
                $found = $true
                break
            }
        }
        if (-not $found) {
            # Fallback to recursive search
            $foundPhp = Get-ChildItem "D:\php" -Recurse -Filter "php.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($foundPhp) {
                $phpExe = $foundPhp.FullName
                $phpVersion = & $phpExe --version 2>$null | Select-Object -First 1
                Write-Host "✓ PHP found at: $phpExe" -ForegroundColor Green
                Write-Host "  Version: $phpVersion" -ForegroundColor Gray
            }
        }
    }
    
    if (-not $phpExe) {
        Write-Host "⚠ PHP not found. API server will not start." -ForegroundColor Yellow
        Write-Host "  Install PHP 8.2+ from https://windows.php.net/download/" -ForegroundColor Yellow
        Write-Host "  Or add PHP to your PATH environment variable." -ForegroundColor Yellow
    }
}

# Start React Web App
Write-Host "`nStarting React Web App..." -ForegroundColor Yellow
$webPath = Join-Path $scriptPath "apps\web"
if (Test-Path $webPath) {
    Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$webPath'; Write-Host 'React Dev Server' -ForegroundColor Green; npm run dev" -WindowStyle Normal
    Write-Host "✓ React app starting in new window (http://localhost:3000)" -ForegroundColor Green
} else {
    Write-Host "✗ Web app directory not found: $webPath" -ForegroundColor Red
}

# Start Laravel API
if ($phpExe) {
    Write-Host "`nStarting Laravel API..." -ForegroundColor Yellow
    $apiPath = Join-Path $scriptPath "apps\api"
    if (Test-Path $apiPath) {
        # Check if .env exists
        $envFile = Join-Path $apiPath ".env"
        if (-not (Test-Path $envFile)) {
            Write-Host "⚠ Warning: .env file not found. API may not work correctly." -ForegroundColor Yellow
            Write-Host "  Run setup-api.ps1 first or create .env manually." -ForegroundColor Yellow
        }
        
        Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$apiPath'; Write-Host 'Laravel API Server' -ForegroundColor Green; & '$phpExe' artisan serve" -WindowStyle Normal
        Write-Host "✓ Laravel API starting in new window (http://localhost:8000)" -ForegroundColor Green
    } else {
        Write-Host "✗ API directory not found: $apiPath" -ForegroundColor Red
    }
}

Write-Host "`n✅ Servers are starting in separate windows!" -ForegroundColor Green
Write-Host "`nAccess the application at:" -ForegroundColor Cyan
Write-Host "  Web App: http://localhost:3000" -ForegroundColor White
if ($phpExe) {
    Write-Host "  API:     http://localhost:8000" -ForegroundColor White
}
