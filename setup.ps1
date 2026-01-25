# Farm ERP v2 Setup Script
Write-Host "Setting up Farm ERP v2..." -ForegroundColor Green

# Check prerequisites
Write-Host "`nChecking prerequisites..." -ForegroundColor Yellow
$nodeVersion = node --version 2>$null
$npmVersion = npm --version 2>$null

if (-not $nodeVersion) {
    Write-Host "ERROR: Node.js is not installed. Please install Node.js 18+ first." -ForegroundColor Red
    exit 1
}
Write-Host "✓ Node.js: $nodeVersion" -ForegroundColor Green

if (-not $npmVersion) {
    Write-Host "ERROR: npm is not installed." -ForegroundColor Red
    exit 1
}
Write-Host "✓ npm: $npmVersion" -ForegroundColor Green

# Install shared package dependencies
Write-Host "`nInstalling shared package dependencies..." -ForegroundColor Yellow
Set-Location packages\shared
npm install
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to install shared package dependencies" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Shared package dependencies installed" -ForegroundColor Green

# Build shared package
Write-Host "`nBuilding shared package..." -ForegroundColor Yellow
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to build shared package" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Shared package built" -ForegroundColor Green
Set-Location ..\..

# Install web app dependencies
Write-Host "`nInstalling web app dependencies..." -ForegroundColor Yellow
Set-Location apps\web
npm install
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to install web app dependencies" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Web app dependencies installed" -ForegroundColor Green
Set-Location ..\..

# Create Laravel .env if it doesn't exist
Write-Host "`nSetting up Laravel environment..." -ForegroundColor Yellow
Set-Location apps\api
if (-not (Test-Path .env)) {
    if (Test-Path .env.example) {
        Copy-Item .env.example .env
        Write-Host "✓ Created .env file from .env.example" -ForegroundColor Green
        Write-Host "⚠ IMPORTANT: Edit apps/api/.env with your Supabase credentials!" -ForegroundColor Yellow
    } else {
        Write-Host "⚠ .env.example not found. You'll need to create .env manually." -ForegroundColor Yellow
    }
} else {
    Write-Host "✓ .env file already exists" -ForegroundColor Green
}
Set-Location ..\..

Write-Host "`n✅ Setup complete!" -ForegroundColor Green
Write-Host "`nNext steps:" -ForegroundColor Cyan
Write-Host "1. Edit apps/api/.env with your Supabase database credentials" -ForegroundColor White
Write-Host "2. Run database migrations (see docs/migrations.sql or use: php artisan migrate)" -ForegroundColor White
Write-Host "3. Generate Laravel key: cd apps/api && php artisan key:generate" -ForegroundColor White
Write-Host "4. Start API: cd apps/api && php artisan serve" -ForegroundColor White
Write-Host "5. Start Web: cd apps/web && npm run dev" -ForegroundColor White
