# Quick Start Guide

## Prerequisites Check

- [ ] PHP 8.2+ installed (`php --version`)
- [ ] Composer installed (`composer --version`)
- [ ] Node.js 18+ installed (`node --version`)
- [ ] Supabase account or local Postgres

## 5-Minute Setup

### 1. Install Dependencies

```bash
# Root
npm install

# Laravel API
cd apps/api
composer install

# React Web
cd ../web
npm install

# Shared Package
cd ../../packages/shared
npm install
npm run build
```

### 2. Database Setup (Supabase)

1. Go to your Supabase project dashboard
2. Open SQL Editor
3. Copy and paste contents of `docs/migrations.sql`
4. Run the SQL

**Note**: The seed tenant ID is `00000000-0000-0000-0000-000000000001`

### 3. Configure Laravel

```bash
cd apps/api
cp .env.example .env
php artisan key:generate
```

Edit `apps/api/.env`:

```env
SUPABASE_DB_HOST=db.xxxxx.supabase.co
SUPABASE_DB_PORT=5432
SUPABASE_DB_DATABASE=postgres
SUPABASE_DB_USERNAME=postgres
SUPABASE_DB_PASSWORD=your_password
```

### 4. Run the Applications

**Terminal 1 - API:**
```bash
cd apps/api
php artisan serve
```
API runs on http://localhost:8000

**Terminal 2 - Web:**
```bash
cd apps/web
npm run dev
```
Web app runs on http://localhost:3000

### 5. Test It

1. Open http://localhost:3000
2. You should see the health check page
3. Navigate to "Daily Book Entries" from the URL or add `/daily-book-entries`
4. You should see 3 sample entries (from seed data)
5. Try creating a new entry
6. Try editing an entry
7. Try deleting an entry (DRAFT only)

## Verify Everything Works

### API Health Check
```bash
curl http://localhost:8000/api/health
```

Expected response:
```json
{"ok":true,"service":"api"}
```

### List Entries (with tenant header)
```bash
curl -H "X-Tenant-Id: 00000000-0000-0000-0000-000000000001" \
     http://localhost:8000/api/daily-book-entries
```

### Run Tests
```bash
cd apps/api
php artisan test
```

## Common Issues

### "No tenant selected" error
- The React app stores tenant ID in localStorage
- Default tenant ID: `00000000-0000-0000-0000-000000000001`
- Check browser console for errors

### Database connection failed
- Verify Supabase credentials in `.env`
- Check that migrations have been run
- Ensure seed data exists

### CORS errors
- API CORS is configured for `http://localhost:3000`
- Check `apps/api/config/cors.php` if using different port

### Shared package not found
- Rebuild shared package: `cd packages/shared && npm run build`
- Reinstall web dependencies: `cd apps/web && npm install`

## Next Steps

- Read `README.md` for detailed documentation
- Check `docs/PHASE_0_NOTES.md` for implementation details
- Review `docs/FILE_TREE.md` for project structure
