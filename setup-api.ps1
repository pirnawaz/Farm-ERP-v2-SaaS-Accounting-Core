# Farm ERP v2 - Laravel API Setup Script
# Run this after PHP and Composer are installed

Write-Host "Setting up Laravel API..." -ForegroundColor Green

# Check prerequisites
Write-Host "`nChecking prerequisites..." -ForegroundColor Yellow
$phpVersion = php --version 2>$null
$composerVersion = composer --version 2>$null

if (-not $phpVersion) {
    Write-Host "ERROR: PHP is not installed or not in PATH." -ForegroundColor Red
    Write-Host "Please install PHP 8.2+ from https://windows.php.net/download/" -ForegroundColor Yellow
    exit 1
}
Write-Host "✓ PHP installed" -ForegroundColor Green

if (-not $composerVersion) {
    Write-Host "ERROR: Composer is not installed or not in PATH." -ForegroundColor Red
    Write-Host "Please install Composer from https://getcomposer.org/download/" -ForegroundColor Yellow
    exit 1
}
Write-Host "✓ Composer installed" -ForegroundColor Green

# Navigate to API directory
Set-Location apps\api

# Check if .env exists
if (-not (Test-Path .env)) {
    Write-Host "`nCreating .env file..." -ForegroundColor Yellow
    
    $envContent = @"
APP_NAME="Farm ERP API"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=pgsql

# Supabase Database Configuration
# TODO: Replace with your actual Supabase database password
SUPABASE_DB_HOST=db.mpsabndgchnwurwrpidp.supabase.co
SUPABASE_DB_PORT=5432
SUPABASE_DB_DATABASE=postgres
SUPABASE_DB_USERNAME=postgres
SUPABASE_DB_PASSWORD=YOUR_PASSWORD_HERE

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120
"@
    
    $envContent | Out-File -FilePath .env -Encoding utf8
    Write-Host "✓ .env file created" -ForegroundColor Green
    Write-Host "⚠ IMPORTANT: Edit apps/api/.env and set SUPABASE_DB_PASSWORD with your database password!" -ForegroundColor Yellow
    Write-Host "   Get it from: https://mpsabndgchnwurwrpidp.supabase.co → Settings → Database" -ForegroundColor Cyan
} else {
    Write-Host "✓ .env file already exists" -ForegroundColor Green
}

# Install dependencies
Write-Host "`nInstalling Laravel dependencies..." -ForegroundColor Yellow
composer install
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to install dependencies" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Dependencies installed" -ForegroundColor Green

# Generate application key
Write-Host "`nGenerating application key..." -ForegroundColor Yellow
php artisan key:generate
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to generate application key" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Application key generated" -ForegroundColor Green

# Test database connection
Write-Host "`nTesting database connection..." -ForegroundColor Yellow
php artisan db:show 2>&1 | Out-Null
if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Database connection successful" -ForegroundColor Green
} else {
    Write-Host "⚠ Database connection failed. Please check your .env file credentials." -ForegroundColor Yellow
}

Set-Location ..\..

Write-Host "`n✅ Laravel API setup complete!" -ForegroundColor Green
Write-Host "`nNext steps:" -ForegroundColor Cyan
Write-Host "1. If database connection failed, edit apps/api/.env with correct Supabase credentials" -ForegroundColor White
Write-Host "2. Start the API server: cd apps/api && php artisan serve" -ForegroundColor White
Write-Host "3. The API will be available at http://localhost:8000" -ForegroundColor White
Write-Host "4. Test the health endpoint: http://localhost:8000/api/health" -ForegroundColor White
