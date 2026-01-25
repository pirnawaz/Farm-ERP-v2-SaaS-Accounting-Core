# Quick Start Guide - Build and Run Farm ERP v2

## Step 1: Enable Required PHP Extensions

Run this PowerShell script to enable required extensions:

```powershell
.\enable-php-extensions.ps1
```

This will enable:
- `openssl` (required for Composer)
- `fileinfo` (required for Laravel)

**After running the script, restart Laragon services** (or restart your computer).

## Step 2: Build the Application

Run the build script:

```cmd
build.bat
```

Or double-click `build.bat` in Windows Explorer.

This will:
1. ✅ Create `.env` file with MySQL configuration
2. ✅ Install Composer dependencies
3. ✅ Generate Laravel application key
4. ✅ Install and build shared package
5. ✅ Install web app dependencies
6. ✅ Run database migrations
7. ✅ Build frontend application

## Step 3: Start the Servers

### Option A: Use the All-in-One Script

```cmd
build-and-start.bat
```

This will build and start both servers automatically.

### Option B: Start Manually

**Terminal 1 - Start Laravel API:**
```cmd
cd apps\api
php artisan serve
```

**Terminal 2 - Start React Web App:**
```cmd
cd apps\web
npm run dev
```

## Access the Application

- **API**: http://localhost:8000
- **Web App**: http://localhost:3000

## Troubleshooting

### Missing PHP Extensions
- Run `enable-php-extensions.ps1`
- Restart Laragon
- Run `build.bat` again

### Database Connection Failed
- Make sure MySQL is running in Laragon
- Verify database `farm_erp` exists
- Check credentials in `apps\api\.env`

### Port Already in Use
- Stop any existing servers on ports 8000 or 3000
- Or change ports in the configuration files

## Quick Commands Reference

```cmd
# Enable PHP extensions
.\enable-php-extensions.ps1

# Build application
build.bat

# Build and start
build-and-start.bat

# Start API only
cd apps\api && php artisan serve

# Start Web only
cd apps\web && npm run dev
```
