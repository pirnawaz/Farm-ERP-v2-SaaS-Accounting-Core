@echo off
echo ========================================
echo   Farm ERP v2 - Build and Start
echo ========================================
echo.

cd /d "%~dp0"

REM First, check if OpenSSL is enabled
echo Checking OpenSSL extension...
php -m 2>nul | findstr /i "openssl" >nul
if errorlevel 1 (
    echo   [WARN] OpenSSL extension not found
    echo   Attempting to enable it...
    powershell -ExecutionPolicy Bypass -File "enable-openssl.ps1"
    if errorlevel 1 (
        echo   [ERROR] Could not enable OpenSSL automatically
        echo   Please run enable-openssl.ps1 manually, then restart Laragon
        pause
        exit /b 1
    )
    echo   [OK] OpenSSL enabled. Please restart Laragon services, then run this script again.
    pause
    exit /b 0
) else (
    echo   [OK] OpenSSL extension is enabled
)

echo.
echo ========================================
echo   Building Application...
echo ========================================
echo.

call build.bat
if errorlevel 1 (
    echo.
    echo [ERROR] Build failed. Please fix the errors above and try again.
    pause
    exit /b 1
)

echo.
echo ========================================
echo   Starting Servers...
echo ========================================
echo.

REM Start Laravel API
echo Starting Laravel API server...
cd apps\api
start "Laravel API Server" cmd /k "php artisan serve"
echo   [OK] Laravel API starting at http://localhost:8000
cd ..\..

REM Start Web App
echo Starting React Web App...
cd apps\web
start "React Dev Server" cmd /k "npm run dev"
echo   [OK] React app starting at http://localhost:3000
cd ..\..

echo.
echo ========================================
echo   Application Started!
echo ========================================
echo.
echo Servers are running in separate windows:
echo   - API: http://localhost:8000
echo   - Web: http://localhost:3000
echo.
echo Press any key to close this window (servers will keep running)...
pause >nul
