@echo off
echo ========================================
echo   Farm ERP v2 - Build Script
echo ========================================
echo.

cd /d "%~dp0"

echo Step 1: Setting up Laravel API environment...
cd apps\api
if not exist .env (
    echo   Creating .env file...
    (
        echo APP_NAME="Farm ERP API"
        echo APP_ENV=local
        echo APP_KEY=
        echo APP_DEBUG=true
        echo APP_TIMEZONE=UTC
        echo APP_LOCALE=en
        echo APP_FALLBACK_LOCALE=en
        echo APP_URL=http://localhost:8000
        echo.
        echo LOG_CHANNEL=stack
        echo LOG_STACK=single
        echo LOG_DEPRECATIONS_CHANNEL=null
        echo LOG_LEVEL=debug
        echo.
        echo DB_CONNECTION=mysql
        echo DB_HOST=127.0.0.1
        echo DB_PORT=3306
        echo DB_DATABASE=farm_erp
        echo DB_USERNAME=root
        echo DB_PASSWORD=
        echo.
        echo CACHE_STORE=file
        echo CACHE_PREFIX=
        echo FILESYSTEM_DISK=local
        echo QUEUE_CONNECTION=database
        echo SESSION_DRIVER=database
        echo SESSION_LIFETIME=120
        echo.
        echo BROADCAST_CONNECTION=log
    ) > .env
    echo   [OK] .env file created
) else (
    echo   [OK] .env file already exists
)

echo.
echo Step 2: Installing Laravel dependencies...
call "C:\laragon\bin\composer\composer.bat" install --no-interaction
if errorlevel 1 (
    echo.
    echo   [ERROR] Failed to install Composer dependencies
    echo   This is likely due to missing PHP extensions (OpenSSL or FileInfo).
    echo.
    echo   To fix this:
    echo   1. Run: enable-php-extensions.ps1
    echo   2. Or manually edit php.ini and uncomment:
    echo      - extension=openssl
    echo      - extension=fileinfo
    echo   3. Restart Laragon services
    echo   4. Run build.bat again
    echo.
    cd ..\..
    pause
    exit /b 1
)
echo   [OK] Composer dependencies installed

echo.
echo Step 3: Generating Laravel application key...
php artisan key:generate --force
if errorlevel 1 (
    echo   [ERROR] Failed to generate application key
    cd ..\..
    pause
    exit /b 1
)
echo   [OK] Application key generated

cd ..\..

echo.
echo Step 4: Installing shared package dependencies...
cd packages\shared
call npm install
if errorlevel 1 (
    echo   [ERROR] Failed to install shared package dependencies
    cd ..\..
    pause
    exit /b 1
)
echo   [OK] Shared package dependencies installed

echo   Building shared package...
call npm run build
if errorlevel 1 (
    echo   [ERROR] Failed to build shared package
    cd ..\..
    pause
    exit /b 1
)
echo   [OK] Shared package built

cd ..\..

echo.
echo Step 5: Installing web app dependencies...
cd apps\web
call npm install
if errorlevel 1 (
    echo   [ERROR] Failed to install web app dependencies
    cd ..\..
    pause
    exit /b 1
)
echo   [OK] Web app dependencies installed

cd ..\..

echo.
echo Step 6: Running database migrations...
cd apps\api
php artisan migrate --force
if errorlevel 1 (
    echo   [WARN] Database migrations failed or database not accessible
    echo   Make sure:
    echo     1. MySQL is running in Laragon
    echo     2. Database 'farm_erp' exists
    echo     3. Database credentials in .env are correct
) else (
    echo   [OK] Database migrations completed
)

cd ..\..

echo.
echo Step 7: Building frontend application...
cd apps\web
call npm run build
if errorlevel 1 (
    echo   [ERROR] Failed to build frontend
    cd ..\..
    pause
    exit /b 1
)
echo   [OK] Frontend built successfully

cd ..\..

echo.
echo ========================================
echo   Build completed successfully!
echo ========================================
echo.
echo To run the application:
echo   1. Start Laravel API:  cd apps\api ^&^& php artisan serve
echo   2. Start Web App:      cd apps\web ^&^& npm run dev
echo.
echo The API will be available at: http://localhost:8000
echo The web app will be available at: http://localhost:3000
echo.
pause
