# Enable OpenSSL Extension Script
# This script helps enable the OpenSSL extension in PHP

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Enable OpenSSL Extension" -ForegroundColor Cyan
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
    } else {
        # Try common Laragon locations
        $commonPaths = @(
            "C:\laragon\bin\php\php-8.5\php.ini",
            "C:\laragon\bin\php\php-8.4\php.ini",
            "C:\laragon\bin\php\php-8.3\php.ini",
            "C:\laragon\bin\php\php-8.2\php.ini",
            "C:\laragon\bin\php\php-8.1\php.ini"
        )
        
        foreach ($path in $commonPaths) {
            if (Test-Path $path) {
                $phpIniPath = $path
                Write-Host "[OK] php.ini found: $phpIniPath" -ForegroundColor Green
                break
            }
        }
    }
} else {
    Write-Host "[ERROR] PHP not found in PATH" -ForegroundColor Red
    Write-Host "Trying common Laragon locations..." -ForegroundColor Yellow
    
    $commonPhpPaths = @(
        "C:\laragon\bin\php\php-8.5\php.exe",
        "C:\laragon\bin\php\php-8.4\php.exe",
        "C:\laragon\bin\php\php-8.3\php.exe",
        "C:\laragon\bin\php\php-8.2\php.exe"
    )
    
    foreach ($phpExe in $commonPhpPaths) {
        if (Test-Path $phpExe) {
            $phpPath = $phpExe
            $phpIniPath = $phpExe -replace "php.exe", "php.ini"
            Write-Host "[OK] PHP found: $phpPath" -ForegroundColor Green
            if (Test-Path $phpIniPath) {
                Write-Host "[OK] php.ini found: $phpIniPath" -ForegroundColor Green
            }
            break
        }
    }
}

if (-not $phpIniPath -or -not (Test-Path $phpIniPath)) {
    Write-Host "[ERROR] Could not find php.ini file" -ForegroundColor Red
    Write-Host "Please manually locate your php.ini file and enable the openssl extension" -ForegroundColor Yellow
    Write-Host "Look for: extension=openssl and uncomment it (remove the semicolon)" -ForegroundColor Yellow
    pause
    exit 1
}

Write-Host ""
Write-Host "Checking current OpenSSL status..." -ForegroundColor Yellow

# Check if OpenSSL is already enabled
$phpIniContent = Get-Content $phpIniPath -Raw
$opensslEnabled = $false

if ($phpIniContent -match '^\s*extension\s*=\s*openssl\s*$' -or $phpIniContent -match '^\s*extension\s*=\s*php_openssl\.dll\s*$') {
    $opensslEnabled = $true
    Write-Host "[OK] OpenSSL extension is already enabled!" -ForegroundColor Green
} elseif ($phpIniContent -match '^\s*;extension\s*=\s*openssl\s*$' -or $phpIniContent -match '^\s*;extension\s*=\s*php_openssl\.dll\s*$') {
    Write-Host "[INFO] OpenSSL extension found but commented out" -ForegroundColor Yellow
    Write-Host "Uncommenting OpenSSL extension..." -ForegroundColor Yellow
    
    # Uncomment openssl extension
    $phpIniContent = $phpIniContent -replace '(?m)^(\s*);extension\s*=\s*openssl\s*$', '$1extension=openssl'
    $phpIniContent = $phpIniContent -replace '(?m)^(\s*);extension\s*=\s*php_openssl\.dll\s*$', '$1extension=php_openssl.dll'
    
    # Backup original
    $backupPath = "$phpIniPath.backup"
    Copy-Item $phpIniPath $backupPath -Force
    Write-Host "[OK] Backup created: $backupPath" -ForegroundColor Green
    
    # Write updated content
    Set-Content -Path $phpIniPath -Value $phpIniContent -NoNewline
    Write-Host "[OK] OpenSSL extension enabled!" -ForegroundColor Green
    $opensslEnabled = $true
} else {
    Write-Host "[INFO] OpenSSL extension not found in php.ini" -ForegroundColor Yellow
    Write-Host "Adding OpenSSL extension..." -ForegroundColor Yellow
    
    # Find the extensions section
    if ($phpIniContent -match '(?s)(; Windows Extensions.*?)(\n\s*;extension=)' -or $phpIniContent -match '(?s)(; Windows Extensions.*?)(\n\s*extension=)') {
        # Add after Windows Extensions section
        $phpIniContent = $phpIniContent -replace '(?s)(; Windows Extensions.*?)(\n\s*;extension=)', "`$1`n`nextension=openssl`$2"
    } else {
        # Add at the end of the file
        $phpIniContent += "`n`n; OpenSSL Extension`nextension=openssl`n"
    }
    
    # Backup original
    $backupPath = "$phpIniPath.backup"
    Copy-Item $phpIniPath $backupPath -Force
    Write-Host "[OK] Backup created: $backupPath" -ForegroundColor Green
    
    # Write updated content
    Set-Content -Path $phpIniPath -Value $phpIniContent -NoNewline
    Write-Host "[OK] OpenSSL extension added!" -ForegroundColor Green
    $opensslEnabled = $true
}

if ($opensslEnabled) {
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Green
    Write-Host "  OpenSSL Extension Enabled!" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "IMPORTANT: Restart Laragon services or restart your computer" -ForegroundColor Yellow
    Write-Host "for the changes to take effect." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "After restarting, run the build script again:" -ForegroundColor Cyan
    Write-Host "  .\build.bat" -ForegroundColor White
    Write-Host ""
}

pause
