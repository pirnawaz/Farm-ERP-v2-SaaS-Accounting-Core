# Build Instructions for Farm ERP v2

Due to path encoding issues with PowerShell, please follow these steps manually:

## Prerequisites Check

Make sure you have:
- ✅ PHP 8.2+ installed and in PATH
- ✅ Composer installed (or available at `C:\laragon\bin\composer\composer.bat`)
- ✅ Node.js 18+ installed
- ✅ npm installed
- ✅ MySQL running in Laragon
- ✅ Database `farm_erp` created in MySQL

## Step-by-Step Build Process

### Step 1: Setup Laravel API Environment

Open PowerShell or Command Prompt and navigate to the project:

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\apps\api"
```

Create `.env` file (if it doesn't exist):

```powershell
# The .env file should contain:
APP_NAME="Farm ERP API"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=farm_erp
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=file
CACHE_PREFIX=
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

BROADCAST_CONNECTION=log
```

### Step 2: Install Composer Dependencies

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\apps\api"
C:\laragon\bin\composer\composer.bat install --no-interaction
```

**Note:** If you see OpenSSL errors, you need to enable the OpenSSL extension:
1. Find your `php.ini` file (usually in `C:\laragon\bin\php\php-8.x\php.ini`)
2. Uncomment or add: `extension=openssl`
3. Restart any running PHP processes

### Step 3: Generate Laravel Application Key

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\apps\api"
php artisan key:generate --force
```

### Step 4: Install and Build Shared Package

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\packages\shared"
npm install
npm run build
```

### Step 5: Install Web App Dependencies

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\apps\web"
npm install
```

### Step 6: Run Database Migrations

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\apps\api"
php artisan migrate --force
```

**Note:** Make sure:
- MySQL is running in Laragon
- Database `farm_erp` exists
- Database credentials in `.env` are correct

### Step 7: Build Frontend

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\apps\web"
npm run build
```

## Running the Application

### Terminal 1 - Start Laravel API:

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\apps\api"
php artisan serve
```

The API will be available at: **http://localhost:8000**

### Terminal 2 - Start Web App:

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core\apps\web"
npm run dev
```

The web app will be available at: **http://localhost:3000**

## Quick Build Script

Alternatively, you can run the batch file:

```powershell
cd "C:\laragon\www\Farm ERP v2 – SaaS Accounting Core"
.\build.bat
```

## Troubleshooting

### OpenSSL Extension Error
- Enable `extension=openssl` in `php.ini`
- Restart PHP/Laragon services

### Database Connection Failed
- Verify MySQL is running
- Check database `farm_erp` exists
- Verify credentials in `.env` file

### Composer Not Found
- Use full path: `C:\laragon\bin\composer\composer.bat`
- Or add Composer to your system PATH

### Node/npm Not Found
- Install Node.js from https://nodejs.org/
- Restart terminal after installation
