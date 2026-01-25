# Next Steps - Getting Your Farm ERP v2 Running

## ✅ What's Already Done

1. ✅ **Shared Package** - Built and ready
2. ✅ **Web App** - Built and ready  
3. ✅ **Database** - Migrations run successfully in Supabase
4. ✅ **Frontend Dev Server** - Running (if started earlier)

## 🔧 What You Need to Do

### Step 1: Install PHP and Composer

**Install PHP 8.2+:**
- Download from: https://windows.php.net/download/
- Choose the "Thread Safe" version for Windows
- Extract to `C:\php` (or your preferred location)
- Add PHP to your PATH:
  - Open System Properties → Environment Variables
  - Add `C:\php` to the PATH variable
  - Restart your terminal/PowerShell

**Install Composer:**
- Download from: https://getcomposer.org/download/
- Run the Windows installer (Composer-Setup.exe)
- It will automatically detect PHP if it's in your PATH
- Restart your terminal/PowerShell after installation

**Verify Installation:**
```powershell
php --version    # Should show PHP 8.2+
composer --version  # Should show Composer version
```

### Step 2: Configure Laravel API

**Option A: Use the Setup Script (Recommended)**
```powershell
.\setup-api.ps1
```

**Option B: Manual Setup**

1. **Create `.env` file** in `apps/api/`:
   ```env
   APP_NAME="Farm ERP API"
   APP_ENV=local
   APP_KEY=
   APP_DEBUG=true
   APP_URL=http://localhost:8000
   
   DB_CONNECTION=pgsql
   SUPABASE_DB_HOST=db.mpsabndgchnwurwrpidp.supabase.co
   SUPABASE_DB_PORT=5432
   SUPABASE_DB_DATABASE=postgres
   SUPABASE_DB_USERNAME=postgres
   SUPABASE_DB_PASSWORD=YOUR_PASSWORD_HERE
   ```

2. **Get your database password:**
   - Go to: https://mpsabndgchnwurwrpidp.supabase.co
   - Navigate to **Settings** → **Database**
   - Copy your database password
   - Replace `YOUR_PASSWORD_HERE` in the `.env` file

3. **Install dependencies:**
   ```powershell
   cd apps\api
   composer install
   ```

4. **Generate application key:**
   ```powershell
   php artisan key:generate
   ```

### Step 3: Start the Servers

**Terminal 1 - Laravel API:**
```powershell
cd apps\api
php artisan serve
```
API will run on: http://localhost:8000

**Terminal 2 - React Web App:**
```powershell
cd apps\web
npm run dev
```
Web app will run on: http://localhost:3000

### Step 4: Verify Everything Works

1. **Test API Health:**
   - Open: http://localhost:8000/api/health
   - Should return: `{"ok":true,"service":"api"}`

2. **Test with Tenant Header:**
   ```powershell
   curl -H "X-Tenant-Id: 00000000-0000-0000-0000-000000000001" http://localhost:8000/api/health
   ```

3. **Open Web App:**
   - Navigate to: http://localhost:3000
   - You should see the health check page
   - Go to: http://localhost:3000/daily-book-entries
   - You should see 3 sample entries from the seed data

## 🎯 Quick Reference

**Your Supabase Project:**
- URL: https://mpsabndgchnwurwrpidp.supabase.co
- Database Host: `db.mpsabndgchnwurwrpidp.supabase.co`
- Default Tenant ID: `00000000-0000-0000-0000-000000000001`

**Ports:**
- Frontend: http://localhost:3000
- Backend: http://localhost:8000

**Important Files:**
- `.env` file: `apps/api/.env` (contains database credentials)
- Migration SQL: `docs/migrations.sql` (already run)

## 🐛 Troubleshooting

**"PHP not recognized"**
- PHP is not in your PATH
- Restart terminal after adding PHP to PATH
- Verify with: `php --version`

**"Composer not recognized"**
- Composer is not in your PATH
- Restart terminal after installing Composer
- Verify with: `composer --version`

**"Database connection failed"**
- Check your `.env` file has correct Supabase credentials
- Verify password in Supabase dashboard
- Test connection: `php artisan db:show`

**"No tenant selected" error in web app**
- The app stores tenant ID in browser localStorage
- Default tenant ID: `00000000-0000-0000-0000-000000000001`
- Check browser console for errors

## 📚 Additional Resources

- Laravel Docs: https://laravel.com/docs
- Supabase Docs: https://supabase.com/docs
- React Docs: https://react.dev
