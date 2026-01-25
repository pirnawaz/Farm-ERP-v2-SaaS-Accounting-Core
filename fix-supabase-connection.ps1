# Fix Supabase Connection - Add SUPABASE_DB_URL to .env
# This helps resolve DNS issues by using connection URL directly

$envFile = Join-Path $PSScriptRoot "apps\api\.env"

if (-not (Test-Path $envFile)) {
    Write-Host "ERROR: .env file not found at: $envFile" -ForegroundColor Red
    exit 1
}

Write-Host "Reading .env file..." -ForegroundColor Green
$lines = Get-Content $envFile

# Check if SUPABASE_DB_URL exists
$hasUrl = $false
$newLines = @()

foreach ($line in $lines) {
    if ($line -match '^\s*SUPABASE_DB_URL\s*=') {
        $hasUrl = $true
        # Update existing URL
        $newLines += "SUPABASE_DB_URL=postgresql://postgres:Nawaz%401580%23@db.mpsabndgchnwurwrpidp.supabase.co:5432/postgres?sslmode=require"
        Write-Host "Updating existing SUPABASE_DB_URL..." -ForegroundColor Yellow
    } else {
        $newLines += $line
    }
}

# If URL doesn't exist, add it after SUPABASE_DB_PASSWORD
if (-not $hasUrl) {
    Write-Host "Adding SUPABASE_DB_URL..." -ForegroundColor Yellow
    $updatedLines = @()
    $added = $false
    
    foreach ($line in $newLines) {
        $updatedLines += $line
        
        # Add SUPABASE_DB_URL after SUPABASE_DB_PASSWORD
        if (-not $added -and $line -match '^\s*SUPABASE_DB_PASSWORD\s*=') {
            $updatedLines += ""
            $updatedLines += "# Full Postgres connection URL (helps with DNS resolution)"
            $updatedLines += "SUPABASE_DB_URL=postgresql://postgres:Nawaz%401580%23@db.mpsabndgchnwurwrpidp.supabase.co:5432/postgres?sslmode=require"
            $added = $true
        }
    }
    
    # If SUPABASE_DB_PASSWORD not found, add at the end
    if (-not $added) {
        $updatedLines += ""
        $updatedLines += "# Full Postgres connection URL (helps with DNS resolution)"
        $updatedLines += "SUPABASE_DB_URL=postgresql://postgres:Nawaz%401580%23@db.mpsabndgchnwurwrpidp.supabase.co:5432/postgres?sslmode=require"
    }
    
    $newLines = $updatedLines
}

# Write back to file
$newLines | Set-Content $envFile -Encoding UTF8

Write-Host "✓ .env file updated!" -ForegroundColor Green
Write-Host ""
Write-Host "Now clearing Laravel cache..." -ForegroundColor Cyan

# Clear cache
$apiPath = Join-Path $PSScriptRoot "apps\api"
if (Test-Path (Join-Path $apiPath "artisan")) {
    Push-Location $apiPath
    php artisan config:clear 2>&1 | Out-Null
    php artisan cache:clear 2>&1 | Out-Null
    Pop-Location
    Write-Host "✓ Cache cleared!" -ForegroundColor Green
} else {
    Write-Host "⚠ Could not find artisan file. Please run manually:" -ForegroundColor Yellow
    Write-Host "  cd apps\api" -ForegroundColor White
    Write-Host "  php artisan config:clear" -ForegroundColor White
    Write-Host "  php artisan cache:clear" -ForegroundColor White
}

Write-Host ""
Write-Host "✅ Done! Try accessing your application now." -ForegroundColor Green
Write-Host ""
Write-Host "If error persists, check:" -ForegroundColor Cyan
Write-Host "1. Internet connection" -ForegroundColor White
Write-Host "2. Firewall settings" -ForegroundColor White
Write-Host "3. Run: ipconfig /flushdns" -ForegroundColor White
