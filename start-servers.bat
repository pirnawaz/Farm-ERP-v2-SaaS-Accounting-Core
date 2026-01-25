@echo off
REM Start Servers Script for Farm ERP v2
REM This batch file handles path encoding issues

echo Starting Farm ERP v2 servers...
echo.

REM Get the script directory
set "SCRIPT_DIR=%~dp0"
cd /d "%SCRIPT_DIR%"

REM Check for Node.js
where node >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Node.js is not installed or not in PATH
    pause
    exit /b 1
)
echo [OK] Node.js found

REM Check for PHP
set PHP_EXE=
where php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [OK] PHP found in PATH
    set PHP_EXE=php
    set PHP_FOUND=1
) else (
    REM Try to find PHP in D: drive
    if exist "D:\php\php-8.5.1-Win32-vs17-x64\php.exe" (
        echo [OK] PHP found at D:\php\php-8.5.1-Win32-vs17-x64\php.exe
        set PHP_EXE=D:\php\php-8.5.1-Win32-vs17-x64\php.exe
        set PHP_FOUND=1
    ) else if exist "D:\php\php-8.5.1-src\php-8.5.1-src\php.exe" (
        echo [OK] PHP found at D:\php\php-8.5.1-src\php-8.5.1-src\php.exe
        set PHP_EXE=D:\php\php-8.5.1-src\php-8.5.1-src\php.exe
        set PHP_FOUND=1
    ) else if exist "D:\php\php.exe" (
        echo [OK] PHP found at D:\php\php.exe
        set PHP_EXE=D:\php\php.exe
        set PHP_FOUND=1
    ) else if exist "D:\php-8.5\php.exe" (
        echo [OK] PHP found at D:\php-8.5\php.exe
        set PHP_EXE=D:\php-8.5\php.exe
        set PHP_FOUND=1
    ) else if exist "D:\php-8.5.1\php.exe" (
        echo [OK] PHP found at D:\php-8.5.1\php.exe
        set PHP_EXE=D:\php-8.5.1\php.exe
        set PHP_FOUND=1
    ) else (
        echo [WARN] PHP not found in PATH or D: drive. API server will not start.
        echo        Install PHP 8.2+ from https://windows.php.net/download/
        echo        Or add PHP to your PATH environment variable.
        set PHP_FOUND=0
    )
)

REM Start React Web App
echo.
echo Starting React Web App...
if exist "apps\web" (
    start "React Dev Server" cmd /k "cd /d apps\web && npm run dev"
    echo [OK] React app starting in new window (http://localhost:3000)
) else (
    echo [ERROR] Web app directory not found: apps\web
)

REM Start Laravel API
if %PHP_FOUND%==1 (
    echo.
    echo Starting Laravel API...
    if exist "apps\api" (
        if not exist "apps\api\.env" (
            echo [WARN] .env file not found. API may not work correctly.
            echo        Run setup-api.ps1 first or create .env manually.
        )
        start "Laravel API Server" cmd /k "cd /d apps\api && %PHP_EXE% artisan serve"
        echo [OK] Laravel API starting in new window (http://localhost:8000)
    ) else (
        echo [ERROR] API directory not found: apps\api
    )
)

echo.
echo ========================================
echo Servers are starting in separate windows!
echo.
echo Access the application at:
echo   Web App: http://localhost:3000
if %PHP_FOUND%==1 (
    echo   API:     http://localhost:8000
)
echo ========================================
echo.
pause
