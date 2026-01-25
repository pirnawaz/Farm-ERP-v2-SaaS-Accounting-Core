# Setup Instructions

Due to path encoding issues, please follow these manual setup steps:

## Step 1: Install Shared Package Dependencies

Open a terminal in the project root and run:

```powershell
cd packages\shared
npm install
npm run build
cd ..\..
```

## Step 2: Install Web App Dependencies

```powershell
cd apps\web
npm install
cd ..\..
```

## Step 3: Configure Laravel API

### 3a. Create .env file

```powershell
cd apps\api
copy .env.example .env
```

### 3b. Edit .env file

Open `apps/api/.env` in a text editor and update these lines with your Supabase credentials:

```env
SUPABASE_DB_HOST=db.xxxxx.supabase.co
SUPABASE_DB_PORT=5432
SUPABASE_DB_DATABASE=postgres
SUPABASE_DB_USERNAME=postgres
SUPABASE_DB_PASSWORD=your_password_here
```

### 3c. Generate Laravel Key

```powershell
php artisan key:generate
```

### 3d. Install Composer Dependencies

```powershell
composer install
```

## Step 4: Set Up Database

### Option A: Using Supabase (Recommended)

1. Go to your Supabase project dashboard
2. Open SQL Editor
3. Copy the entire contents of `docs/migrations.sql`
4. Paste and run it in the SQL Editor

### Option B: Using Laravel Migrations

```powershell
cd apps\api
php artisan migrate
```

## Step 5: Start the Applications

### Terminal 1 - Laravel API:

```powershell
cd apps\api
php artisan serve
```

The API will run on http://localhost:8000

### Terminal 2 - React Web App:

```powershell
cd apps\web
npm run dev
```

The web app will run on http://localhost:3000

## Step 6: Verify Setup

1. Open http://localhost:3000 in your browser
2. You should see the health check page
3. Navigate to http://localhost:3000/daily-book-entries
4. You should see 3 sample entries from the seed data

## Troubleshooting

### "No tenant selected" error
- The app stores tenant ID in localStorage
- Default tenant ID: `00000000-0000-0000-0000-000000000001`
- Check browser console for errors

### Database connection failed
- Verify Supabase credentials in `apps/api/.env`
- Check that migrations have been run
- Ensure seed data exists (tenant ID should be in database)

### Shared package not found
- Rebuild: `cd packages/shared && npm run build`
- Reinstall web: `cd apps/web && npm install`

### CORS errors
- API CORS is configured for `http://localhost:3000`
- Check `apps/api/config/cors.php` if using different port

## Quick Test Commands

### Test API Health:
```powershell
curl http://localhost:8000/api/health
```

### Test API with Tenant:
```powershell
curl -H "X-Tenant-Id: 00000000-0000-0000-0000-000000000001" http://localhost:8000/api/daily-book-entries
```

### Run Tests:
```powershell
cd apps\api
php artisan test
```
