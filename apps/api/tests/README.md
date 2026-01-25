# API Tests

Tests run on **PostgreSQL** (see `phpunit.xml` and `.env.testing`). SQLite is not supported because migrations use `gen_random_uuid()`, ENUMs, and `ALTER TABLE ... SET DEFAULT`.

## Setup

1. **Create the test database**
   ```bash
   createdb -U postgres farm_erp_test
   # Or: psql -U postgres -c "CREATE DATABASE farm_erp_test;"
   ```

2. **pgcrypto (for `gen_random_uuid()`)**
   - Most PostgreSQL installs include `pgcrypto`; `gen_random_uuid()` is often available by default.
   - If you see an error about `gen_random_uuid`, run on `farm_erp_test`:
     ```sql
     CREATE EXTENSION IF NOT EXISTS pgcrypto;
     ```

3. **DB credentials**  
   `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` are taken from `.env`. `DB_CONNECTION` and `DB_DATABASE` are set in `phpunit.xml` for tests.

---

### Laragon (Windows)

One-command setup for the test database and `pgcrypto`:

1. **Start Laragon** and ensure **PostgreSQL** is running.
2. **Create the test database once** (idempotent; safe to re-run):
   ```powershell
   .\scripts\create-test-db.ps1    # from project root
   .\create-test-db.ps1            # if already in scripts\
   ```
   In PowerShell use `.\` before the script name.
   Optional env vars: `DB_NAME` (or `DB_DATABASE`), `DB_USER` (or `DB_USERNAME`), `DB_HOST`, `DB_PORT`, `DB_PASSWORD`. Defaults: `farm_erp_test`, `postgres`, `localhost`, `5432`.
3. **Run tests:**
   ```powershell
   cd apps\api; php artisan test
   ```

---

## Run

```bash
php artisan test
```
