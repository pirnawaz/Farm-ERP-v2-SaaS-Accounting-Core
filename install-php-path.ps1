# Add PHP to PATH Environment Variable
# Run this script as Administrator to add PHP to system PATH

Write-Host "PHP PATH Installation Script" -ForegroundColor Green
Write-Host "============================" -ForegroundColor Green
Write-Host ""

$phpPath = "D:\php\php-8.5.1-Win32-vs17-x64"

# Check if PHP exists at the specified path
if (-not (Test-Path "$phpPath\php.exe")) {
    Write-Host "[ERROR] PHP not found at: $phpPath\php.exe" -ForegroundColor Red
    Write-Host "Please verify the PHP installation path." -ForegroundColor Yellow
    exit 1
}

Write-Host "[OK] PHP found at: $phpPath\php.exe" -ForegroundColor Green
& "$phpPath\php.exe" --version | Select-Object -First 1
Write-Host ""

# Check if running as Administrator
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "[WARN] Not running as Administrator" -ForegroundColor Yellow
    Write-Host "This script needs Administrator privileges to modify system PATH." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Option 1: Run PowerShell as Administrator and run this script again" -ForegroundColor Cyan
    Write-Host "Option 2: Add PHP to User PATH (current user only)" -ForegroundColor Cyan
    Write-Host ""
    $choice = Read-Host "Add to User PATH instead? (Y/N)"
    
    if ($choice -eq "Y" -or $choice -eq "y") {
        $currentPath = [Environment]::GetEnvironmentVariable("Path", "User")
        if ($currentPath -notlike "*$phpPath*") {
            [Environment]::SetEnvironmentVariable("Path", "$currentPath;$phpPath", "User")
            Write-Host "[OK] PHP added to User PATH" -ForegroundColor Green
            Write-Host "Please restart your terminal/PowerShell for changes to take effect." -ForegroundColor Yellow
        } else {
            Write-Host "[INFO] PHP is already in User PATH" -ForegroundColor Cyan
        }
    } else {
        Write-Host ""
        Write-Host "To add PHP to PATH manually:" -ForegroundColor Cyan
        Write-Host "1. Open System Properties → Environment Variables" -ForegroundColor White
        Write-Host "2. Edit the 'Path' variable" -ForegroundColor White
        Write-Host "3. Add: $phpPath" -ForegroundColor White
        Write-Host "4. Restart your terminal" -ForegroundColor White
    }
} else {
    Write-Host "[OK] Running as Administrator" -ForegroundColor Green
    Write-Host "Adding PHP to System PATH..." -ForegroundColor Yellow
    
    $currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
    if ($currentPath -notlike "*$phpPath*") {
        [Environment]::SetEnvironmentVariable("Path", "$currentPath;$phpPath", "Machine")
        Write-Host "[OK] PHP added to System PATH" -ForegroundColor Green
        Write-Host ""
        Write-Host "Please restart your terminal/PowerShell for changes to take effect." -ForegroundColor Yellow
    } else {
        Write-Host "[INFO] PHP is already in System PATH" -ForegroundColor Cyan
    }
}

Write-Host ""
Write-Host "Verification:" -ForegroundColor Cyan
Write-Host "After restarting your terminal, run: php --version" -ForegroundColor White
Write-Host "You should see PHP version information." -ForegroundColor White
