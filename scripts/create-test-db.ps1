# create-test-db.ps1
# Creates the PostgreSQL test database and enables pgcrypto. Idempotent; safe to run multiple times.
# Assumes PostgreSQL is available (e.g. via Laragon). Uses psql only.
#
# Env vars (optional; defaults shown):
#   DB_NAME or DB_DATABASE = farm_erp_test
#   DB_USER or DB_USERNAME = postgres
#   DB_HOST               = localhost
#   DB_PORT               = 5432
#   DB_PASSWORD           = (empty; set for auth; also sets PGPASSWORD for psql)

$ErrorActionPreference = "Stop"

# Resolve psql
$psql = $null
try { $null = Get-Command psql -ErrorAction Stop; $psql = "psql" } catch {}
if (-not $psql) {
    $laragon = if ($env:LARAGON_ROOT) { $env:LARAGON_ROOT } else { "C:\laragon" }
    $found = Get-ChildItem -Path "$laragon\bin\postgresql\*\bin\psql.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($found) { $psql = $found.FullName } else {
        Write-Host "ERROR: psql not found. Ensure PostgreSQL is installed and its bin is in PATH (e.g. start Laragon and add PostgreSQL bin)." -ForegroundColor Red
        exit 1
    }
}

# Config from env with defaults
$dbName = if ($env:DB_NAME) { $env:DB_NAME } elseif ($env:DB_DATABASE) { $env:DB_DATABASE } else { "farm_erp_test" }
$dbUser = if ($env:DB_USER) { $env:DB_USER } elseif ($env:DB_USERNAME) { $env:DB_USERNAME } else { "postgres" }
$dbHost = if ($env:DB_HOST) { $env:DB_HOST } else { "localhost" }
$dbPort = if ($env:DB_PORT) { $env:DB_PORT } else { "5432" }
if ($env:DB_PASSWORD -ne $null -and $env:DB_PASSWORD -ne "") { $env:PGPASSWORD = $env:DB_PASSWORD }

$psqlArgs = @("-h", $dbHost, "-p", $dbPort, "-U", $dbUser)

function Invoke-Psql {
    param([string]$Db, [string]$Query, [switch]$TuplesOnly, [switch]$SuppressNotice)
    $a = @($psqlArgs) + @("-d", $Db)
    if ($TuplesOnly) { $a += @("-t", "-A") }
    if ($SuppressNotice) { $Query = "SET client_min_messages TO error; " + $Query }
    $a += @("-c", $Query)
    & $psql @a 2>&1
}

Write-Host "PostgreSQL test DB setup (database: $dbName, host: $dbHost`:$dbPort)" -ForegroundColor Cyan

# 1) Check if database exists
$safeName = $dbName -replace "'", "''"
$check = Invoke-Psql -Db "postgres" -Query "SELECT 1 FROM pg_database WHERE datname='$safeName'" -TuplesOnly
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Cannot connect to PostgreSQL at ${dbHost}:${dbPort}. Is it running? (Start Laragon and ensure PostgreSQL is started.)" -ForegroundColor Red
    exit 1
}

if ($check -match "1") {
    Write-Host "Database '$dbName' already exists." -ForegroundColor Green
} else {
    # 2) Create database
    $create = Invoke-Psql -Db "postgres" -Query "CREATE DATABASE $dbName"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Failed to create database '$dbName'." -ForegroundColor Red
        exit 1
    }
    Write-Host "Created database '$dbName'." -ForegroundColor Green
}

# 3) Enable pgcrypto on the target database (idempotent)
$ext = Invoke-Psql -Db $dbName -Query "CREATE EXTENSION IF NOT EXISTS pgcrypto;" -SuppressNotice
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to enable pgcrypto on '$dbName'." -ForegroundColor Red
    exit 1
}
Write-Host "Extension pgcrypto is enabled." -ForegroundColor Green

Write-Host "Done. Run tests with: cd apps\api; php artisan test" -ForegroundColor Cyan
