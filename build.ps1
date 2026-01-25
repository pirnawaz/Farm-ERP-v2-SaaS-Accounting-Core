# Farm ERP v2 - Build Script
# This script builds and sets up the entire application

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Farm ERP v2 - Build Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Get the script directory
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $scriptDir

# Check prerequisites
Write-Host "Checking prerequisites..." -ForegroundColor Yellow

# Check PHP
$phpPath = $null
if (Get-Command php -ErrorAction SilentlyContinue) {
    $phpPath = (Get-Command php).Source
    Write-Host "[OK] PHP found: $phpPath" -ForegroundColor Green
} else {
    Write-Host "[ERROR] PHP not found in PATH" -ForegroundColor Red
    Write-Host "  Please ensure PHP 8.2+ is installed and in your PATH" -ForegroundColor Yellow
    exit 1
}

# Check Composer
$composerPath = $null
if (Get-Command composer -ErrorAction SilentlyContinue) {
    $composerPath = "composer"
    Write-Host "[OK] Composer found in PATH" -ForegroundColor Green
} elseif (Test-Path "C:\laragon\bin\composer\composer.bat") {
    $composerPath = "C:\laragon\bin\composer\composer.bat"
    Write-Host "[OK] Composer found: $composerPath" -ForegroundColor Green
} else {
    Write-Host "[ERROR] Composer not found" -ForegroundColor Red
    Write-Host "  Please install Composer from https://getcomposer.org/" -ForegroundColor Yellow
    exit 1
}

# Check Node.js
if (Get-Command node -ErrorAction SilentlyContinue) {
    $nodeVersion = node --version
    Write-Host "[OK] Node.js found: $nodeVersion" -ForegroundColor Green
} else {
    Write-Host "[ERROR] Node.js not found" -ForegroundColor Red
    Write-Host "  Please install Node.js 18+ from https://nodejs.org/" -ForegroundColor Yellow
    exit 1
}

# Check npm
if (Get-Command npm -ErrorAction SilentlyContinue) {
    $npmVersion = npm --version
    Write-Host "[OK] npm found: $npmVersion" -ForegroundColor Green
} else {
    Write-Host "[ERROR] npm not found" -ForegroundColor Red
    exit 1
}

Write-Host ""

# Step 1: Setup Laravel API .env file
Write-Host "Step 1: Setting up Laravel API environment..." -ForegroundColor Cyan
Set-Location apps\api

if (-not (Test-Path .env)) {
    Write-Host "  Creating .env file..." -ForegroundColor Yellow
    $envContent = @"
APP_NAME="Farm ERP API"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=farm_erp
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=file
CACHE_PREFIX=
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

BROADCAST_CONNECTION=log
"@
    $envContent | Out-File -FilePath .env -Encoding utf8 -NoNewline
    Write-Host "  [OK] .env file created" -ForegroundColor Green
} else {
    Write-Host "  [OK] .env file already exists" -ForegroundColor Green
}

# Step 2: Install Composer dependencies
Write-Host ""
Write-Host "Step 2: Installing Laravel dependencies..." -ForegroundColor Cyan
& $composerPath install --no-interaction
if ($LASTEXITCODE -ne 0) {
    Write-Host "  [ERROR] Failed to install Composer dependencies" -ForegroundColor Red
    Write-Host "  Note: If you see OpenSSL errors, enable the openssl extension in php.ini" -ForegroundColor Yellow
    Set-Location ..\..
    exit 1
}
Write-Host "  [OK] Composer dependencies installed" -ForegroundColor Green

# Step 3: Generate Laravel key
Write-Host ""
Write-Host "Step 3: Generating Laravel application key..." -ForegroundColor Cyan
php artisan key:generate --force
if ($LASTEXITCODE -ne 0) {
    Write-Host "  [ERROR] Failed to generate application key" -ForegroundColor Red
    Set-Location ..\..
    exit 1
}
Write-Host "  [OK] Application key generated" -ForegroundColor Green

Set-Location ..\..

# Step 4: Install and build shared package
Write-Host ""
Write-Host "Step 4: Installing shared package dependencies..." -ForegroundColor Cyan
Set-Location packages\shared
npm install
if ($LASTEXITCODE -ne 0) {
    Write-Host "  [ERROR] Failed to install shared package dependencies" -ForegroundColor Red
    Set-Location ..\..
    exit 1
}
Write-Host "  [OK] Shared package dependencies installed" -ForegroundColor Green

Write-Host "  Building shared package..." -ForegroundColor Yellow
if (Test-Path package.json -PathType Leaf) {
    if ((Get-Content package.json | ConvertFrom-Json).scripts.build) {
        npm run build
        if ($LASTEXITCODE -ne 0) {
            Write-Host "  [ERROR] Failed to build shared package" -ForegroundColor Red
            Set-Location ..\..
            exit 1
        }
        Write-Host "  [OK] Shared package built" -ForegroundColor Green
    } else {
        Write-Host "  [WARN] No build script found in shared package" -ForegroundColor Yellow
    }
} else {
    Write-Host "  [WARN] No package.json found in shared package" -ForegroundColor Yellow
}

Set-Location ..\..

# Step 5: Install web app dependencies
Write-Host ""
Write-Host "Step 5: Installing web app dependencies..." -ForegroundColor Cyan
Set-Location apps\web
npm install
if ($LASTEXITCODE -ne 0) {
    Write-Host "  [ERROR] Failed to install web app dependencies" -ForegroundColor Red
    Set-Location ..\..
    exit 1
}
Write-Host "  [OK] Web app dependencies installed" -ForegroundColor Green
Set-Location ..\..

# Step 6: Run database migrations
Write-Host ""
Write-Host "Step 6: Running database migrations..." -ForegroundColor Cyan
Set-Location apps\api
php artisan migrate --force
if ($LASTEXITCODE -ne 0) {
    Write-Host "  [WARN] Database migrations failed or database not accessible" -ForegroundColor Yellow
    Write-Host "  Make sure:" -ForegroundColor Yellow
    Write-Host "    1. MySQL is running in Laragon" -ForegroundColor White
    Write-Host "    2. Database 'farm_erp' exists" -ForegroundColor White
    Write-Host "    3. Database credentials in .env are correct" -ForegroundColor White
} else {
    Write-Host "  [OK] Database migrations completed" -ForegroundColor Green
}
Set-Location ..\..

# Step 7: Build frontend
Write-Host ""
Write-Host "Step 7: Building frontend application..." -ForegroundColor Cyan
Set-Location apps\web
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Host "  [ERROR] Failed to build frontend" -ForegroundColor Red
    Set-Location ..\..
    exit 1
}
Write-Host "  [OK] Frontend built successfully" -ForegroundColor Green
Set-Location ..\..

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  Build completed successfully!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "To run the application:" -ForegroundColor Cyan
Write-Host "  1. Start Laravel API:  cd apps\api && php artisan serve" -ForegroundColor White
Write-Host "  2. Start Web App:      cd apps\web && npm run dev" -ForegroundColor White
Write-Host ""
Write-Host "The API will be available at: http://localhost:8000" -ForegroundColor White
Write-Host "The web app will be available at: http://localhost:3000" -ForegroundColor White
