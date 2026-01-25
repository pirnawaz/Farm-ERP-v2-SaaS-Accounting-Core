# Enable Required PHP Extensions Script
# This script enables OpenSSL and FileInfo extensions in PHP

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Enable PHP Extensions" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Find PHP installation
$phpPath = $null
$phpIniPath = $null

# Check if PHP is in PATH
if (Get-Command php -ErrorAction SilentlyContinue) {
    $phpPath = (Get-Command php).Source
    Write-Host "[OK] PHP found: $phpPath" -ForegroundColor Green
    
    # Get php.ini location
    $phpIniOutput = php --ini 2>&1
    $phpIniPath = ($phpIniOutput | Select-String "Loaded Configuration File").ToString() -replace "Loaded Configuration File:\s*", ""
    
    if ($phpIniPath -and (Test-Path $phpIniPath)) {
        Write-Host "[OK] php.ini found: $phpIniPath" -ForegroundColor Green
    }
}

# Try common locations if not found
if (-not $phpIniPath -or -not (Test-Path $phpIniPath)) {
    Write-Host "Searching for php.ini in common locations..." -ForegroundColor Yellow
    
    $commonPaths = @(
        "D:\php\php-8.5.1-Win32-vs17-x64\php.ini",
        "C:\laragon\bin\php\php-8.5\php.ini",
        "C:\laragon\bin\php\php-8.4\php.ini",
        "C:\laragon\bin\php\php-8.3\php.ini",
        "C:\laragon\bin\php\php-8.2\php.ini"
    )
    
    foreach ($path in $commonPaths) {
        if (Test-Path $path) {
            $phpIniPath = $path
            Write-Host "[OK] php.ini found: $phpIniPath" -ForegroundColor Green
            break
        }
    }
}

if (-not $phpIniPath -or -not (Test-Path $phpIniPath)) {
    Write-Host "[ERROR] Could not find php.ini file" -ForegroundColor Red
    Write-Host "Please manually locate your php.ini file" -ForegroundColor Yellow
    Write-Host "Run: php --ini" -ForegroundColor Yellow
    pause
    exit 1
}

Write-Host ""
Write-Host "Enabling required extensions..." -ForegroundColor Yellow

# Read php.ini content
$phpIniContent = Get-Content $phpIniPath -Raw
$modified = $false

# List of extensions to enable
$extensions = @(
    @{ Name = "openssl"; Pattern = "extension\s*=\s*openssl" },
    @{ Name = "fileinfo"; Pattern = "extension\s*=\s*fileinfo" }
)

foreach ($ext in $extensions) {
    $extName = $ext.Name
    $pattern = $ext.Pattern
    
    # Check if already enabled
    if ($phpIniContent -match "(?m)^\s*$pattern\s*$") {
        Write-Host "  [OK] $extName extension is already enabled" -ForegroundColor Green
    }
    # Check if commented out
    elseif ($phpIniContent -match "(?m)^\s*;$pattern\s*$") {
        Write-Host "  Enabling $extName extension..." -ForegroundColor Yellow
        $phpIniContent = $phpIniContent -replace "(?m)^(\s*);$pattern\s*$", "`$1$pattern"
        $modified = $true
        Write-Host "  [OK] $extName extension enabled" -ForegroundColor Green
    }
    # Not found, add it
    else {
        Write-Host "  Adding $extName extension..." -ForegroundColor Yellow
        
        # Try to find Windows Extensions section
        if ($phpIniContent -match '(?s)(; Windows Extensions.*?)(\n\s*;extension=)') {
            $phpIniContent = $phpIniContent -replace '(?s)(; Windows Extensions.*?)(\n\s*;extension=)', "`$1`n`nextension=$extName`$2"
        } else {
            # Add at the end
            $phpIniContent += "`n`n; $extName Extension`nextension=$extName`n"
        }
        $modified = $true
        Write-Host "  [OK] $extName extension added" -ForegroundColor Green
    }
}

if ($modified) {
    # Backup original
    $backupPath = "$phpIniPath.backup"
    Copy-Item $phpIniPath $backupPath -Force
    Write-Host ""
    Write-Host "[OK] Backup created: $backupPath" -ForegroundColor Green
    
    # Write updated content
    Set-Content -Path $phpIniPath -Value $phpIniContent -NoNewline
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Green
    Write-Host "  Extensions Enabled!" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "IMPORTANT: Restart Laragon services or restart your computer" -ForegroundColor Yellow
    Write-Host "for the changes to take effect." -ForegroundColor Yellow
} else {
    Write-Host ""
    Write-Host "[OK] All required extensions are already enabled!" -ForegroundColor Green
}

Write-Host ""
Write-Host "After restarting, run the build script:" -ForegroundColor Cyan
Write-Host "  .\build.bat" -ForegroundColor White
Write-Host ""

pause
