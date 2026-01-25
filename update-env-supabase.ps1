# Script to update .env file with SUPABASE_DB_URL
# Run this from the project root directory

$envFile = "apps\api\.env"

if (-not (Test-Path $envFile)) {
    Write-Host "ERROR: .env file not found at: $envFile" -ForegroundColor Red
    Write-Host "Please make sure you're running this from the project root directory" -ForegroundColor Yellow
    exit 1
}

Write-Host "Updating .env file..." -ForegroundColor Green

# Read current content
$content = Get-Content $envFile -Raw

# Check if SUPABASE_DB_URL already exists
if ($content -match 'SUPABASE_DB_URL\s*=') {
    Write-Host "SUPABASE_DB_URL already exists. Updating..." -ForegroundColor Yellow
    # Replace existing SUPABASE_DB_URL
    $content = $content -replace 'SUPABASE_DB_URL\s*=.*', 'SUPABASE_DB_URL=postgresql://postgres:Nawaz%401580%23@db.mpsabndgchnwurwrpidp.supabase.co:5432/postgres?sslmode=require'
} else {
    Write-Host "Adding SUPABASE_DB_URL..." -ForegroundColor Yellow
    # Add SUPABASE_DB_URL after SUPABASE_DB_PASSWORD
    if ($content -match 'SUPABASE_DB_PASSWORD\s*=') {
        $content = $content -replace '(SUPABASE_DB_PASSWORD\s*=.*)', "`$1`n`n# Full Postgres connection URL (for DNS resolution)`nSUPABASE_DB_URL=postgresql://postgres:Nawaz%401580%23@db.mpsabndgchnwurwrpidp.supabase.co:5432/postgres?sslmode=require"
    } else {
        # Add at the end if pattern not found
        $content += "`n`n# Full Postgres connection URL (for DNS resolution)`nSUPABASE_DB_URL=postgresql://postgres:Nawaz%401580%23@db.mpsabndgchnwurwrpidp.supabase.co:5432/postgres?sslmode=require"
    }
}

# Write back to file
Set-Content -Path $envFile -Value $content -NoNewline

Write-Host "✓ .env file updated successfully!" -ForegroundColor Green
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "1. Clear Laravel cache: cd apps\api && php artisan config:clear" -ForegroundColor White
Write-Host "2. Test connection: php artisan db:show" -ForegroundColor White
