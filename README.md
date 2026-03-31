# Terrava Farm ERP v2 — SaaS Accounting Core

A multi-tenant SaaS accounting and farm management system (Terrava) built as a monorepo: **Laravel API** (backend), **React + Vite** (frontend), and a shared **TypeScript** package. Supports Supabase Postgres (and optionally MySQL for local/Laragon). The repo uses **npm workspaces**; running `npm install` at the root also builds the shared package (`postinstall`).

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11+-FF2D20?logo=laravel)](https://laravel.com)
[![React](https://img.shields.io/badge/React-18+-61DAFB?logo=react)](https://react.dev)
[![Supabase](https://img.shields.io/badge/Supabase-Postgres-3ECF8E?logo=supabase)](https://supabase.com)

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Database Setup](#database-setup)
- [Configuration](#configuration)
- [Staging / production deployment](#staging--production-deployment)
- [Production access & safety](#production-access--safety)
- [Running the Application](#running-the-application)
- [API Overview](#api-overview)
- [Frontend Modules](#frontend-modules)
- [Development Scripts](#development-scripts)
- [Testing](#testing)
- [Project Structure](#project-structure)
- [Tenant navigation (sidebar)](#tenant-navigation-sidebar)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

- **Multi-tenant SaaS** — Tenant isolation, platform admin, and tenant-level modules
- **Platform admin** — Tenant list, activate/suspend/archive, minimal plan field (no billing), controlled impersonation with audit logging; **platform audit log viewer** (tenant audit logs with filters); **tenant lifecycle**: reset tenant admin password (token or direct), archive/unarchive (platform_audit_log); production safety: header-based auth disabled unless `DEV_IDENTITY_ENABLED` or local/testing
- **Roles & permissions** — `platform_admin`, `tenant_admin`, `accountant`, `operator`. Single source: `apps/web/src/config/permissions.ts` (capabilities + role mapping). **Platform admin** only: view/manage all tenants, enable/disable modules per tenant. **Tenant admin** only: manage users, assign roles, enable/disable tenant modules, close crop cycles. **Accountant**: full operational/accounting in tenant (post, reverse, settlements, reports) but cannot manage users/modules. **Operator**: create/edit own transactions and view data; cannot post/reverse or manage users/modules. Backend enforces via route middleware; frontend uses `can(permission)` and Role Permissions Matrix (`/app/admin/roles`) for reference.
- **Land & Projects** — Land parcels, crop cycles (close/reopen with preview), land allocations (owner and Hari), projects, project rules
- **Land Leases (Maqada)** — Land leases per project/parcel/landlord, accruals (draft/post), posting to DUE_TO_LANDLORD and expense; reversal of posted accruals (new posting group, no mutation); **Landlord Statement** report (ledger-backed, read-only); traceability from accrual to posting group and reversal
- **Operational Transactions** — Draft/post workflow, posting groups, reversals
- **Treasury** — Payments (draft/post), advances, allocation preview and posting. **Apply payment to sales**: `GET payments/{id}/apply-sales/preview` (FIFO or manual), `POST payments/{id}/apply-sales`, `POST payments/{id}/unapply-sales`; creates/voids **sale_payment_allocations** (status ACTIVE/VOID). Payment posting creates **allocation rows** (type `PAYMENT`, scope `PARTY_ONLY`/`LANDLORD_ONLY`) so landlord statement and settlement views include them; posting group `source_type` is `PAYMENT`. Payment method determines credit account: **CASH** credits CASH, **BANK** credits BANK (system account `BANK` seeded). **Payment reversal**: `POST /api/payments/{id}/reverse` (posting_date, optional reason) creates a reversal posting group (negated ledger entries and allocation row); original posting group is immutable; payment stores `reversal_posting_group_id`, `reversed_at`, `reversed_by`, `reversal_reason`. Reversed payments are excluded from party balance/statement totals.
- **AR & Sales** — Sales documents with lines and inventory allocations, posting, reversals. **AR statement** per party (`GET parties/{id}/ar-statement`), **open sales** (`GET parties/{id}/receivables/open-sales`). Apply/unapply creates **SalePaymentAllocation** rows (ACTIVE/VOID); only ACTIVE (or null) allocations count toward open balance. **Reversal guards**: cannot reverse a payment or sale while it has ACTIVE allocations (409 until unapplied/reversed). **AR Aging report** (`GET /ar/aging?as_of=YYYY-MM-DD`): auditable, reconciles to open invoices; buckets per customer (current, 1_30, 31_60, 61_90, 90_plus) and grand totals; uses ACTIVE allocation sums only; excludes reversed sales. Sales margin reports.
- **Settlements** — Project-based and sales-based settlements with share rules, preview and posting, reversals
- **Settlement Pack (Governance)** — Per-project settlement pack generation (idempotent per version), summary totals, and full transaction register; DRAFT/FINAL status; re-generate when not final
- **Share Rules** — Configurable share rules for crop cycles, projects, and sales (margin or revenue basis)
- **Harvests** — Harvest tracking with lines, posting to inventory, production allocation, project association
- **Inventory** — **Inputs** (inventory items): CRUD, **Edit** (name, SKU, category, UoM, valuation, active), **Deactivate/Activate**, **Delete** (only when unused; 422 with message if item has transactions). List/detail include `can_delete`. Item dropdowns in GRN/Issue/Transfer/Adjustment show only active items; existing records show inactive as "Name (Inactive)" and deleted as "(Deleted item)". Items, stores, UOMs, categories; **Supplies Received** (GRNs), **Supplies Used** (issues) with allocation support (share rules, explicit percentages, project rules), **Move Between Stores** (transfers), adjustments; stock on-hand and movements. Farm terminology: Inputs, Supplies Received, Supplies Used, Move Between Stores.
- **Labour** — Workers (Hari), work logs, wage accrual, wage payments
- **Machinery** — Machine management (with active/inactive status), work logs with meter tracking, rate cards (with activity type support), machinery charges, maintenance jobs and types, profitability reports; posting and reversals
- **Crop Operations** — Activity types, activities (inputs, labour); post consumes stock and accrues wages
- **Accounting Core** — Immutable ledger: `posting_groups` and `ledger_entries` are never updated or deleted; period locking enforced at posting time; **journal entries** (draft/post/reverse); **accounting periods** (create, close, reopen); **bank reconciliation** (create, statement lines, match/unmatch, finalize); **financial statements** from ledger only: **Profit & Loss** (income statement for date range, optional compare period) and **Balance Sheet** (as-of date, optional compare, equation check); accounts tenant-scoped with system accounts (AR/AP/CASH/BANK) and seeded chart; posting date cutoffs driven by `posting_groups.posting_date`
- **Accounting Guards** — Immutability protection for posted transactions, balanced posting validation
- **Audit Logs** — Transaction audit trail for posted operations; **platform audit log** viewer (GET `/api/platform/audit-logs`, filters: tenant_id, actor_user_id, action, date range; platform_admin only)
- **Reports** — Trial balance, **Profit & Loss** (income statement), **Balance Sheet** (with equation check), general ledger, project statement, project P&L, crop cycle P&L, account balances, cashbook, **AR ageing** (including auditable `GET /ar/aging` — open invoice balances per customer with bucket totals and grand totals), customer balances, AP ageing, supplier balances, AR/AP control reconciliation, yield reports, party ledger, party summary, **landlord statement** (Maqada, when `land_leases` module enabled), role ageing, crop cycle distribution, settlement statement, cost per unit, sales margin; **crop reports** (require `projects_crop_cycles`): **crop-category-acres**, **crop-costs** (expense cost and cost per acre by crop/category/cycle), **crop-profitability** (cost, revenue, margin and per-acre metrics; group by crop/category/cycle; include unassigned option), **crop-profitability-trend** (month-by-month margin per acre; group by category/crop/all); reconciliation reports (project, crop-cycle, supplier AP); bank reconciliation; CSV export with Terrava-branded filenames; print-friendly layouts
- **Reconciliation** — Project settlement reconciliation, supplier AP reconciliation, reconciliation dashboard; ledger reconciliation for audit and debugging
- **Crop Cycle Close** — Close crop cycle with preview; **Accounting Period Close (v2)**: full closing entries that zero all income/expense accounts for the cycle period, clear via CURRENT_EARNINGS, and roll to RETAINED_EARNINGS in one PERIOD_CLOSE posting group; idempotent (one close run per cycle); crop cycle lock enforced (no posting after CLOSED); `POST crop-cycles/{id}/close`, `GET crop-cycles/{id}/close-run`; crop-cycle-based settlements (preview and post); accounting corrections and guards
- **Dashboard (Accounting Overview)** — Role-based dashboard at **Finance & Review → Accounting Overview**; widgets, quick actions, onboarding panel for new users, empty states. **Global crop cycle scope**: header-level selector (All Crop Cycles / single cycle); scope persists per tenant in `localStorage`; dashboard summary API accepts optional `scope_type` and `scope_id` (read-only, no ledger writes); default selection is the active OPEN cycle when present. **Farm Pulse** and **Today** are the primary daily entry points under Farm Operations.
- **Navigation & UX** — Farm-first sidebar driven by **permissions only** (modules never hide items; they only disable). Central config: `apps/web/src/config/nav.ts` — **`getNavDomains()`** is the primary model (**domain → section → item**); **`getNavGroups()`** is deprecated (flattened legacy). Route helpers: `apps/web/src/config/navMatch.ts`. **Contributor notes:** `docs/NAVIGATION.md`. **Domains:** **Farm** (Farm Pulse, Today, Alerts); **Operations** (Land & Crops, Work & Harvest with **Drafts (Unposted)** at `/app/transactions`, Machinery submenu, Inventory, People); **Finance** (Money & treasury; Accounting & reports — Accounting Overview, Review Queue, reports, journals, settlement packs, etc.); **Governance** (Governance overview, **Farm Integrity**, **Audit Logs**); **Settings** (Farm Profile, Users, Roles, Modules, Localisation). If a user has permission but a required module is off, the nav item is shown **disabled** (grey, tooltip "Module not enabled"); click sends tenant admin to Modules page or shows "Ask a tenant admin to enable &lt;module&gt;". **Breadcrumbs**: `PageHeader` with breadcrumb trail; hierarchy matches sidebar. **Farm-first terminology**: `apps/web/src/config/terminology.ts` and `<Term>` component; use `term('key')` for farmer-facing copy.
- **Settings** — Tenant settings, farm profile (create when missing), modules, users

---

## Architecture

| Layer        | Stack                          |
|-------------|---------------------------------|
| **Backend** | Laravel 11+ (API-only)         |
| **Frontend**| React 18, Vite, Tailwind CSS   |
| **Database**| Supabase Postgres (or MySQL)   |
| **Shared**  | TypeScript types & API client  |

### Monorepo layout

| Path               | Description                          |
|--------------------|--------------------------------------|
| `apps/api`         | Laravel API                          |
| `apps/web`         | React + Vite web app                 |
| `packages/shared`  | Shared TypeScript types & API client |
| `docs`             | Documentation, migrations, phase notes |

---

## Prerequisites

- **PHP** 8.2+ (with `openssl`, `fileinfo` for Laravel)
- **Composer**
- **Node.js** 18+
- **npm** (or yarn)
- **Supabase** account (or local Postgres/MySQL)

---

## Quick Start

For a condensed checklist, see [docs/QUICK_START.md](docs/QUICK_START.md).

### Windows (Laragon)

1. **Enable PHP extensions** (if needed):
   ```powershell
   .\enable-php-extensions.ps1
   ```
   Restart Laragon if required.

2. **Build**:
   ```cmd
   build.bat
   ```
   Or: `build-and-start.bat` to build and start both API and web.

3. **Start manually** (if not using `build-and-start.bat`):
   - Terminal 1: `cd apps\api` → `php artisan serve`
   - Terminal 2: `cd apps\web` → `npm run dev`

### Linux / macOS

1. **Root & shared**:
   ```bash
   npm install
   cd packages/shared && npm install && npm run build && cd ../..
   ```

2. **API**:
   ```bash
   cd apps/api
   cp .env.example .env
   # Edit .env (Supabase or local DB)
   composer install
   php artisan key:generate
   php artisan migrate   # or run docs/migrations.sql in Supabase
   ```

3. **Web**:
   ```bash
   cd apps/web
   npm install
   npm run dev
   ```

- **API:** http://localhost:8000  
- **Web:** http://localhost:3000  

---

## Database Setup

### Supabase (recommended)

1. Create a project at [supabase.com](https://supabase.com).
2. In **SQL Editor**, run the contents of `docs/migrations.sql`.
3. In `apps/api/.env`, set:
   ```env
   DB_CONNECTION=pgsql
   SUPABASE_DB_HOST=db.xxxxx.supabase.co
   SUPABASE_DB_PORT=5432
   SUPABASE_DB_DATABASE=postgres
   SUPABASE_DB_USERNAME=postgres
   SUPABASE_DB_PASSWORD=your_password
   ```

### Local MySQL (e.g. Laragon)

1. Create DB: `farm_erp`
2. In `apps/api/.env`:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=farm_erp
   DB_USERNAME=root
   DB_PASSWORD=
   ```
3. Run: `php artisan migrate`

### Seed / default tenant

- **Option A — migrations.sql:** Seed data in `docs/migrations.sql` includes tenant  
  `00000000-0000-0000-0000-000000000001`.
- **Option B — Staging seeder (recommended after plain migrate):** From `apps/api` run:
  ```bash
  php artisan db:seed --class=StagingSeeder
  ```
  This upserts a **Staging Farm** tenant (slug `staging`, id `11111111-1111-1111-1111-111111111111`), an admin user with known credentials, then runs `ModulesSeeder` and `SystemAccountsSeeder`. Idempotent (safe to run multiple times).
  - **Staging login:** Tenant ID `11111111-1111-1111-1111-111111111111`, email `admin@staging.local`, password `StagingAdmin1!` (change in production).
- The web app and API require a tenant identifier on login: send header **`X-Tenant-Slug: staging`** or **`X-Tenant-Id: 11111111-1111-1111-1111-111111111111`**. In the UI, select Tenant and use slug `staging` or the UUID above.

#### Quick start: create farm + user (one command after migrate)

After a normal build (e.g. `build.bat` runs only migrations), the DB has no tenant or user. To get a working farm login in one step:

```bash
cd apps/api
php artisan migrate
php artisan db:seed --class=StagingSeeder
```

Then log in with:

| Field | Value |
|-------|--------|
| **Farm / Tenant** | `staging` (or UUID `11111111-1111-1111-1111-111111111111`) |
| **Email** | `admin@staging.local` |
| **Password** | `StagingAdmin1!` |

The login request must identify the tenant (e.g. the web UI sends `X-Tenant-Slug` or `X-Tenant-Id`). Use slug **staging** or the tenant UUID when selecting the farm.

---

## Configuration

- **API env:** `apps/api/.env` — app key, DB, Supabase, etc.
- **Key env vars (API):**
  - `APP_ENV` — `local`, `staging`, or `production`; affects auth cookie `secure` flag and validation.
  - `APP_URL` — Base URL of the API (e.g. `https://api.example.com`). Auth cookie is set `secure=true` only when `APP_ENV=production` or `APP_URL` begins with `https://`, so staging over HTTP works.
  - `DEV_IDENTITY_ENABLED` — When `false` (default), `X-User-Id` and `X-User-Role` headers are **ignored** in production-like environments; header-only requests return 401/403. Set `true` only for dev/testing. In `local` or `testing` env, header auth is always allowed. See [Production access & safety](#production-access--safety).
- **Key env vars (Web, `apps/web`):**
  - `VITE_API_URL` (optional) — When set, the frontend uses this as the API base. When unset, uses same-origin so Nginx can proxy `/api` in production.
  - `VITE_FORCE_ALL_MODULES_ENABLED` — When `true`, all modules are treated as enabled (E2E / dev). Sidebar and module gating skip real module state.
  - `VITE_DEBUG_NAV` — When `true` or `1`, console logs per nav item: key, requiredPermission, canResult, requiredModules, modulesEnabledResult.
  - `VITE_DEBUG_MODULES` — When `true` or `1`, console logs tenantId, enabledModules array (ModulesContext), and per nav item requiredModules and isEnabled (sidebar).
  - `VITE_ENABLE_ORCHARDS` / `VITE_ENABLE_LIVESTOCK` — Force show Orchards/Livestock in sidebar when addon API is not used.
- **Cookie auth:** The app uses a **custom auth cookie** (`farm_erp_auth_token`), not Laravel Sanctum SPA. There is no CSRF cookie flow; mitigation is `httpOnly`, `Secure` in production, and `SameSite` (see below). The frontend must send credentials with API calls: the shared `api-client` uses `credentials: 'include'` so the cookie is sent on same-origin or configured CORS origins.
- **Token lifecycle:** Tokens include `exp` (expiry, configurable via `AUTH_TOKEN_TTL_HOURS`, default 7 days) and `v` (token version). `POST /api/auth/logout` clears the cookie; `POST /api/auth/logout-all` increments the user's token version (invalidating all existing tokens) and clears the cookie; `POST /api/auth/change-password` (tenant) and `POST /api/platform/auth/change-password` (platform) update password, set `last_password_change_at`, increment token version, and issue a new token.
- **Rate limiting:** Login (platform and tenant), accept-invite, and create-invitation are rate-limited (configurable via `RATE_LIMIT_*` env vars). Responses return **429** with message "Too many attempts. Try again in X seconds."
- **Cookie security (production):** Set `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax` (or `none` only if you need cross-site subdomain cookies), and optionally `SESSION_DOMAIN` for subdomains (see [Deploying with subdomains](#deploying-with-subdomains)).
- **Web:** `apps/web` reads from API; tenant and auth are applied via shared client / context.
- **CORS:** Laravel CORS is set for `http://localhost:3000`; adjust in `apps/api/config/cors.php` if needed.
- **Full env reference:** See `apps/api/.env.example` for all API environment variables.

---

## Staging / production deployment

The app is set up for droplet/staging deployment with the following behaviour:

- **Auth cookie:** The login cookie is `secure=true` only when `APP_ENV=production` or `APP_URL` begins with `https://`. Otherwise it is `secure=false` so staging over HTTP works. `httpOnly` stays `true`.
- **Frontend API base:** When `VITE_API_URL` is not set, the shared API client uses same-origin (`''`), so you can serve the web app and API from the same host and proxy `/api` with Nginx to the Laravel backend. Set `VITE_API_URL` only when the API is on a different origin.
- **Staging seed:** Run `php artisan db:seed --class=StagingSeeder` from `apps/api` to create/update the Staging tenant and admin user (see [Seed / default tenant](#seed--default-tenant)). Use the documented staging credentials only for staging; change the admin password in production.

### Deploying with subdomains

When tenants are reached via subdomains (e.g. `acme.terrava.app`):

1. **API:** Set `APP_ROOT_DOMAIN` (e.g. `terrava.app`) so tenant resolution can use the subdomain slug. Set `SESSION_DOMAIN` to the leading-dot root (e.g. `.terrava.app`) so the auth cookie is sent to all subdomains. Ensure `SESSION_SECURE_COOKIE=true` and `SESSION_SAME_SITE=lax` (or `none` if the SPA is on a different subdomain than the API and you need cross-site cookies).
2. **CORS:** If the web app is on a different subdomain than the API, configure CORS to allow that origin explicitly (no wildcard `*` when using credentials). Ensure the API sends `Access-Control-Allow-Credentials: true`. The shared API client uses `credentials: 'include'`.
3. **Cookie name scope:** The auth cookie (`farm_erp_auth_token`) and platform restore cookie (`farm_erp_platform_saved`) are httpOnly and use the same `SESSION_DOMAIN`; avoid name collisions with other apps on the same root domain.
4. **Config health (platform admin):** `GET /api/platform/config-health` returns runtime booleans and values: `root_domain_set`, `session_domain_set`, `secure_cookie_on`, `same_site`, `auth_token_ttl_hours`. Use this to verify subdomain/cookie configuration.
5. **Nginx:** Proxy both the SPA and `/api` (or your API path) so that cookie and tenant resolution work; alternatively serve API and SPA from the same subdomain.

### Production access & safety

In production (or when `APP_ENV` is not `local`/`testing` and `DEV_IDENTITY_ENABLED` is not `true`):

- **Header-based identity is disabled:** Any middleware that reads `X-User-Id` or `X-User-Role` ignores those headers; identity is taken only from request attributes (e.g. set by cookie/session for platform routes).
- **Header-only requests fail:** API calls that rely only on `X-User-Id` / `X-User-Role` (e.g. from scripts or Postman without a real auth cookie) return **401** or **403**.
- **Platform admin:** Must sign in via platform login (cookie); the cookie is then used to resolve identity. E2E and PHPUnit use `DEV_IDENTITY_ENABLED=true` in `.env.testing` so header auth continues to work in tests.

### Destructive Artisan commands and migration rollback safety

To avoid accidental data loss in staging or production:

- **Blocked Artisan commands:** When `APP_ENV` is not `local` or `testing`, the following commands are **blocked** (the process exits with an error before running): `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`. This is enforced by `App\Providers\ConsoleSafetyServiceProvider` (registered in `apps/api/bootstrap/app.php`). In `local` or `testing` these commands are allowed.
- **Seed migration rollbacks:** Migrations that seed catalog or tenant data (e.g. crop catalog and crop-cycle migration) implement a **data safety rule**: their `down()` methods do **not** delete or overwrite data when run outside `local` or `testing`. Destructive rollback (e.g. clearing seeded tables) runs only in `local`/`testing`. In staging or production, `php artisan migrate:rollback` will not remove real accounting or tenant data from these migrations.

---

## Running the Application

| Service | Command              | URL                |
|---------|----------------------|--------------------|
| API     | `cd apps/api && php artisan serve` | http://localhost:8000 |
| Web     | `cd apps/web && npm run dev`       | http://localhost:3000  |

From repo root:

- `npm run dev` — run API and web concurrently (requires `npm run dev:api` and `npm run dev:web` under the hood)
- `npm run dev:api` — API only
- `npm run dev:web` — Web only

---

## API Overview

All tenant-scoped APIs use `X-Tenant-Id` (and/or auth). Role and module middleware apply as in `routes/api.php`.

**Login (Phase 1 — Global Identity):** One login screen. Call `POST /api/auth/login` with `{ email, password }` and no tenant header. Response is `mode: "platform"` (route to platform), `mode: "tenant"` (single farm; route to dashboard), or `mode: "select_tenant"` (show farm picker, then `POST /api/auth/select-tenant` with `{ tenant_id }`). Run `php artisan identities:backfill-from-users` to backfill identities from existing users.

| Area            | Examples                                                                 |
|-----------------|---------------------------------------------------------------------------|
| **Health**      | `GET /api/health`                                                        |
| **Dashboard**   | `GET /api/dashboard/summary` — optional query: `scope_type=crop_cycle`, `scope_id=<uuid>` (read-only; scopes metrics to one crop cycle) |
| **Auth**        | `POST /api/auth/login` (unified: email + password; no tenant header), `POST /api/auth/select-tenant` (body: `tenant_id`; after multi-farm login), `POST /api/auth/logout`, `GET /api/auth/me`; `POST /api/auth/set-password-with-token` (body: `token`, `new_password`; no tenant; for platform-issued reset tokens) |
| **Platform**    | `GET/POST /api/platform/tenants`, `GET/PUT /api/platform/tenants/{id}`; `GET /api/platform/tenants/{id}/modules`, `PUT .../modules`; `POST .../reset-admin-password` (body optional: `new_password`; else returns one-time token), `POST .../archive`, `POST .../unarchive` (platform_admin only, audited in `platform_audit_log`); `GET /api/platform/audit-logs` (identity audit; query: `tenant_id`, `action`, `from`, `to`, `q`, `per_page`, `page`; platform_admin only); `GET /api/platform/config-health` (platform_admin only); `GET /api/platform/impersonation`, `POST .../start`, `POST .../stop` (platform_admin only, audited) |
| **Dev**         | `GET/POST /api/dev/tenants`, `POST /api/dev/tenants/{id}/activate`        |
| **Users**       | `apiResource('users')`                                                   |
| **Parties**     | `apiResource('parties')`, `.../balances`, `.../statement`, `.../receivables/open-sales` |
| **Land**        | `apiResource('land-parcels')`, `.../documents`                           |
| **Land Leases** | `apiResource('land-leases')`; `GET/POST/PUT/DELETE /land-lease-accruals`, `POST /land-lease-accruals/{id}/post`, `POST /land-lease-accruals/{id}/reverse` (tenant_admin, `land_leases` module) |
| **Crop cycles** | `apiResource('crop-cycles')`, `GET .../close-preview`, `GET .../close-run`, `POST .../close` (optional `as_of`), `POST .../reopen`, `POST .../open` |
| **Land allocations** | `apiResource('land-allocations')`                                   |
| **Projects**    | `apiResource('projects')`, `POST /projects/from-allocation`              |
| **Project rules**| `GET/PUT /projects/{id}/rules`                                           |
| **Share rules**  | `apiResource('share-rules')`                                            |
| **Operational transactions** | `apiResource('operational-transactions')`, `POST .../post`       |
| **Settlement**  | `POST /projects/{id}/settlement/preview`, `.../offset-preview`, `.../post`; `GET/POST /settlements`, `GET /settlements/preview`, `POST /settlements/{id}/post`, `POST /settlements/{id}/reverse` |
| **Settlement Pack** | `POST /api/projects/{projectId}/settlement-pack` (generate, idempotent per project+version), `GET /api/settlement-packs/{id}` (pack + full transaction register) |
| **Payments**    | `apiResource('payments')`, `.../allocation-preview`, `GET .../apply-sales/preview`, `POST .../apply-sales`, `POST .../unapply-sales`, `.../post`, `.../reverse` (tenant_admin, accountant) |
| **Advances**    | `apiResource('advances')`, `.../post`                                    |
| **Sales**       | `apiResource('sales')`, `.../post`, `.../reverse`                      |
| **AR Aging**    | `GET /ar/aging?as_of=YYYY-MM-DD` — open invoice balances per customer (buckets: current, 1_30, 31_60, 61_90, 90_plus), grand totals; ACTIVE allocations only; excludes reversed sales (reports module) |
| **Inventory**   | Items, stores, UOMs, categories; GRNs, issues, transfers, adjustments; `.../post`, `.../reverse`; `stock/on-hand`, `stock/movements` |
| **Labour**      | `v1/labour/workers`, `v1/labour/work-logs` (CRUD, `.../post`, `.../reverse`); `v1/labour/payables/outstanding` |
| **Crop Ops**    | `v1/crop-ops/activity-types` (CRUD); `v1/crop-ops/activities` (timeline, CRUD, `.../post`, `.../reverse`); `v1/crop-ops/harvests` (CRUD, lines, `.../post`, `.../reverse`) |
| **Machinery**   | `v1/machinery/machines` (CRUD); `v1/machinery/maintenance-types` (CRUD); `v1/machinery/work-logs` (CRUD, `.../post`, `.../reverse`); `v1/machinery/rate-cards` (CRUD); `v1/machinery/charges` (list, show); `v1/machinery/maintenance-jobs` (CRUD, `.../post`, `.../reverse`); `v1/machinery/reports/profitability` |
| **Posting groups** | `GET /posting-groups/{id}`, `.../ledger-entries`, `.../allocation-rows`, `.../reverse`, `.../reversals` |
| **Reports**     | `trial-balance`, `profit-loss` (from/to, optional compare), `balance-sheet` (as_of, optional compare_as_of), `general-ledger`, `project-statement`, `project-pl`, `crop-cycle-pl`, `account-balances`, `cashbook`, `ar-ageing`, `GET ar/aging` (auditable AR aging), `customer-balances`, `customer-balance-detail`, `ap-ageing`, `supplier-balances`, `supplier-balance-detail`, `ar-control-reconciliation`, `ap-control-reconciliation`, `yield`, `party-ledger`, `party-summary`, `landlord-statement` (requires `land_leases`), `role-ageing`, `crop-cycle-distribution`, `settlement-statement`, `cost-per-unit`, `sales-margin`; **crop reports** (require `projects_crop_cycles`): `reports/crop-category-acres`, `reports/crop-costs`, `reports/crop-profitability` (from, to, group_by, include_unassigned), `reports/crop-profitability-trend` (from, to, group_by category|crop|all, include_unassigned); `reports/reconciliation/project`, `reports/reconciliation/crop-cycle`, `reports/reconciliation/supplier-ap` |
| **Bank reconciliation** | `GET/POST bank-reconciliations`, `GET bank-reconciliations/{id}`, `POST .../clear`, `.../unclear`, `.../finalize`, `.../statement-lines`, `.../statement-lines/{lineId}/match`, `.../unmatch`, `.../void` |
| **Accounting**  | `GET/POST journals`, `GET/PUT journals/{id}`, `POST journals/{id}/post`, `POST journals/{id}/reverse`; `GET/POST accounting-periods`, `POST accounting-periods/{id}/close`, `POST .../reopen`, `GET .../events`; **Period close**: `POST crop-cycles/{id}/close` (body: optional `as_of` date), `GET crop-cycles/{id}/close-run` (returns period_close_run or 404) |
| **Reconciliation** | `GET /reconciliation/project/{id}`, `GET /reconciliation/supplier/{party_id}` |
| **Settings**    | `GET/PUT /settings/tenant`; **tenant modules**: `GET /api/tenant/modules` (read-only for all tenant roles — tenant_admin, accountant, operator; used by sidebar for module state), `PUT /api/tenant/modules` (tenant_admin only); `tenant/farm-profile` (GET → `{exists,farm}`, POST create, PUT update); `tenant/users` (tenant_admin only) |

Exact routes, methods, and middleware are in `apps/api/routes/api.php`. Module keys are canonical in backend (`modules` table / ModulesSeeder) and frontend `apps/web/src/config/moduleKeys.ts`; sidebar and Modules page use the same list so enabled state is consistent.

---

## Frontend Modules

The web app includes pages (and routes) for:

- **Accounting Overview** (dashboard at `/app/dashboard`), **Health**
- **Daily book entries**, **Operational transactions** (list/detail; labels use "Record" / "Pending Review" via terminology), **Posting group detail** (`/app/posting-groups/:id`)
- **Parties**, **Sales**, **Payments** (list, detail with Post and **Reverse** for posted payments; reverse modal: posting date, reason), **Advances**
- **Land parcels**, **Land leases** (when `land_leases` module enabled: list, detail with accruals, post/reverse accruals, view posting group and reversal), **Land allocations**, **Crop cycles** (with close/reopen and detail), **Projects**, **Project rules**, **Share rules**, **Settlements** (project-based, sales-based, crop-cycle-based), **Settlement Pack** (view pack at `/app/settlement-packs/:id` with summary, transaction register, re-generate when not FINAL; generate from Settlement page), **Harvests**
- **Inventory:** **Inputs** list (items) with Edit modal, Deactivate/Activate, Delete (only when `can_delete`; confirm dialogs). Item selection in GRN/Issue/Transfer/Adjustment forms shows only active items; history views show "(Inactive)" or "(Deleted item)" where applicable. Items, stores, categories, UOMs, **Supplies Received** (GRNs), **Supplies Used** (issues, with allocation configuration), **Move Between Stores** (transfers), adjustments, stock on-hand, movements (Back + breadcrumbs on internal pages). GRN detail edit: doc_date pre-filled and sent as YYYY-MM-DD; save error shows API message.
- **Labour:** workers, work logs, payables outstanding (when module enabled)
- **Machinery:** machines, work logs, rate cards, charges, maintenance jobs and types, profitability reports (when `machinery` module enabled)
- **Crop Operations:** activity types, activities (inputs, labour), timeline (when `crop_ops` enabled)
- **Reports:** trial balance, **Profit & Loss**, **Balance Sheet**, general ledger, project statement, project P&L, crop cycle P&L, account balances, cashbook, AR ageing, customer balances, AP ageing, supplier balances, yield reports, sales margin, party ledger, party summary, **landlord statement** (when `land_leases` enabled), role ageing, crop cycle distribution, settlement statement; **Crop Profitability** (cost, revenue, margin per acre; group by crop/category/cycle; data-quality warnings for unassigned postings) and **Profitability Trend** (month-by-month margin per acre chart and table; group by category/crop/all; when `projects_crop_cycles` enabled); reconciliation dashboard; **bank reconciliation** (list and detail: statement lines, match/unmatch, finalize); CSV export functionality; print-friendly layouts
- **Dashboard (Accounting Overview):** under Finance & Review; role-based widgets, quick actions, onboarding panel, empty states; **global crop cycle scope selector** in header (All Crop Cycles / single cycle; persisted per tenant; dashboard numbers respect selected scope)
- **Navigation:** Domain-based sidebar (**Farm**, **Operations**, **Finance**, **Governance**, **Settings**); see `docs/NAVIGATION.md` and `getNavDomains()` in `apps/web/src/config/nav.ts`. Collapsible **Machinery** submenu under Operations; **breadcrumbs** on Sales, Payments, Reports, Settlement Pack, and other core pages (hierarchy matches sidebar). Farmer-facing labels use **terminology** (`term()`) for consistency (e.g. "Post to Accounts", "Reverse Posting", "Pending Review", "Field Work").
- **Settings:** tenant, modules, farm profile (admin), users (admin), localisation (**Governance** holds Farm Integrity and tenant Audit Logs; see `docs/NAVIGATION.md`)
- **Platform (platform_admin only):** tenant list at `/app/platform/tenants` (status badge: active/suspended/archived, plan dropdown, impersonate); **Audit Logs** at `/app/platform/audit-logs` (table, filters: tenant, actor user ID, action, date range, pagination); tenant detail with plan, modules, **Support actions** (Reset admin password — generate token or set directly; Archive / Unarchive tenant), and impersonate; impersonation banner in tenant app with “Impersonating: {tenant}” and exit; tenant detail with plan and impersonate

Access to some areas is gated by **roles** and **tenant modules**. Module keys (e.g. `projects_crop_cycles`, `crop_ops`, `inventory`, `land`, `land_leases`, `labour`, `machinery`, `ar_sales`, `treasury_payments`, `treasury_advances`, `settlements`, `reports`) are defined in backend `modules` table and frontend `apps/web/src/config/moduleKeys.ts`; GET `/api/tenant/modules` returns enabled state for the current tenant so accountant and operator see correct sidebar state (disabled items when a module is off).

---

## Development Scripts

| Script / command       | Purpose                                    |
|------------------------|--------------------------------------------|
| `enable-php-extensions.ps1` | Enable OpenSSL, fileinfo for Laravel |
| `enable-openssl.ps1`       | Enable OpenSSL in php.ini (Laragon); restart Laragon after |
| `build.bat` / `build.ps1`   | Full build (env, composer, shared, web, migrate, frontend build) |
| `build-and-start.bat`      | Build then start API + web in separate windows (checks OpenSSL first) |
| `start-servers.bat` / `start-servers.ps1` | Start API and web in separate windows (no build) |
| `setup-api.ps1`            | API .env and composer setup               |
| `scripts/create-test-db.ps1` | Create test DB (see script for target)  |
| `npm run build`             | Build shared package + web app (root: `npm run build`; runs `build:shared` then `build:web`) |
| `npm run build:shared`       | Build `packages/shared` only               |
| `npm run build:web`          | Build shared then `apps/web`               |
| `npm run typecheck` / `npm run typecheck:web` | TypeScript check (web app)        |
| `npm run clean`             | Remove `packages/shared/dist` and `apps/web/dist` |
| `npm run clean:shared`      | Remove `packages/shared/dist` only |
| `npm run clean:web`         | Remove `apps/web/dist` only       |
| `npm run e2e`               | Start API then run Playwright E2E (uses `E2E_PROFILE` or default) |
| `npm run e2e:core`          | E2E with profile `core`                   |
| `npm run e2e:all`           | E2E with profile `all` (e.g. platform admin flows) |
| `npx playwright install` (from `apps/web`) | First-time: install Playwright browsers for E2E |
| `php artisan db:seed --class=StagingSeeder` (from `apps/api`) | Seed/upsert Staging tenant + admin, system accounts, modules |

---

## Testing

### Backend (Laravel)

Backend tests require **PostgreSQL**. See [apps/api/tests/README.md](apps/api/tests/README.md) for test DB setup (including Windows: `.\scripts\create-test-db.ps1` from repo root).

```bash
cd apps/api
php artisan test
```

Covers tenant isolation, CRUD, validation, platform admin (tenant list, suspend/activate/archive, impersonation gating and audit), **dev identity production guard** (header-only rejected when `DEV_IDENTITY_ENABLED` off), **platform audit log** (list, filters, pagination), **tenant lifecycle** (reset admin password with/without token, set-password-with-token, archive/unarchive, platform_audit_log), and other feature tests. To run only platform admin and impersonation tests:

```bash
php artisan test --filter=PlatformAdminTenantAndImpersonation
```

To run production-safety and platform feature tests:

```bash
php artisan test tests/Feature/DevIdentityProductionGuardTest.php
php artisan test tests/Feature/PlatformAuditLogTest.php
php artisan test tests/Feature/PlatformTenantLifecycleTest.php
```

**Role permission enforcement** (RolePermissionEnforcementTest): accountant cannot access tenant users or update tenant modules but can GET tenant modules (for sidebar) and dashboard; operator cannot post/reverse; non–platform_admin cannot access platform tenants.

```bash
php artisan test tests/Feature/RolePermissionEnforcementTest.php
```

Tests expect PostgreSQL (see `apps/api/tests/README.md`). Create the test DB once (e.g. `scripts/create-test-db.ps1` on Windows). Feature tests include **Inventory Items (InvItemCrudTest):** can_update_item, can_deactivate_item, can_activate_item, cannot_delete_used_item_returns_422, can_delete_unused_item_returns_204, index_includes_can_delete. Other coverage: **Crop Cycle Close** (CropCycleCloseTest: full closing entries zero income/expense, multiple accounts zeroed, loss scenario, idempotency, lock enforcement, tenant isolation, snapshot/rule_snapshot), **Settlement Pack** (generate returns expected shape/totals, GET returns register rows, tenant isolation, idempotency), **Land Lease accrual posting and reversal** (post creates PG/ledger, reverse creates reversal PG and negates entries, idempotent second reverse, tenant isolation), **Landlord Statement** (ledger-backed report, opening/closing balance, lines ordered by date), **Payments** (posting creates posting group with `source_type=PAYMENT`, allocation row `PAYMENT`, ledger entries; method BANK credits BANK account; reverse creates reversal posting group and negated allocation row; cannot reverse twice), **Payment apply/unapply** (PaymentApplySalesTest: preview FIFO, apply FIFO/manual, unapply voids allocations, reversed sale/payment excluded from open sales), **Reversal guards** (ReversalGuardsTest: cannot reverse payment or sale while ACTIVE allocations exist; 409 until unapplied), **AR Statement** (ARStatementTest), **AR Aging report** (ARAgingReportTest: buckets per customer and grand totals, apply payment reduces open balance, unapply restores it, reversed sale excluded, default as_of), **Financial Statements** (FinancialStatementsTest: Profit & Loss for range with income/expense totals and net profit, Balance Sheet as-of with equation check, compare period deltas, tenant isolation), **Accounting period locking** (AccountingPeriodLockingTest), and **Bank reconciliation** (BankReconciliationTest, BankStatementLinesTest):

```bash
php artisan test tests/Feature/InvItemCrudTest.php
php artisan test --filter=CropCycleCloseTest
php artisan test tests/Feature/SettlementPackTest.php
php artisan test --filter=LandLeaseAccrualPostingTest
php artisan test --filter=LandLeaseAccrualReversalTest
php artisan test --filter=LandlordStatementReportTest
php artisan test --filter=PaymentTest
php artisan test tests/Feature/PaymentApplySalesTest.php
php artisan test tests/Feature/ReversalGuardsTest.php
php artisan test tests/Feature/ARStatementTest.php
php artisan test tests/Feature/ARAgingReportTest.php
php artisan test tests/Feature/FinancialStatementsTest.php
php artisan test tests/Feature/AccountingPeriodLockingTest.php
php artisan test tests/Feature/BankReconciliationTest.php
php artisan test tests/Feature/CropProfitabilityReportTest.php
php artisan test tests/Feature/CropProfitabilityTrendReportTest.php
```

### Frontend E2E (Playwright)

Playwright specs live under `apps/web/playwright/`. Run from repo root:

```bash
# First time only: install browsers
cd apps/web && npx playwright install && cd ../..

npm run e2e          # starts API, then runs E2E (default profile)
npm run e2e:core     # E2E with profile "core"
npm run e2e:all      # E2E with profile "all" (includes platform admin)
```

Platform admin flows (tenant list, impersonation, audit logs) require a profile that logs in as `platform_admin` (e.g. set `E2E_PROFILE=all` or use seed that creates a platform admin). Specs: `apps/web/playwright/specs/15_platform_tenant_impersonation.spec.ts`, `16_platform_login_and_modules.spec.ts` (includes audit logs page load).

### Frontend unit tests (Vitest)

From `apps/web`: `npm run test` (Vitest), `npm run test:ui` for UI mode; `npm run lint` for ESLint.

---

## Tenant navigation (sidebar)

The tenant app sidebar is **config-driven** and **domain-based** (Farm → Operations → Finance → Governance → Settings), with optional **section** headings under Operations and Finance. **`getNavDomains()`** in `apps/web/src/config/nav.ts` is the source of truth; **`getNavGroups()`** is deprecated. Route matching for active states: `apps/web/src/config/navMatch.ts`. **Contributor rules** (new pages, route stability, no flat nav): **`docs/NAVIGATION.md`**.

---

## Project Structure

```
.
├── apps/
│   ├── api/                    # Laravel API
│   │   ├── app/Domains/        # Domain logic (e.g. Accounting/PeriodClose, Accounting/Reports, Governance/SettlementPack)
│   │   ├── app/Http/Controllers/
│   │   ├── app/Http/Middleware/
│   │   ├── app/Models/
│   │   ├── app/Providers/      # ConsoleSafetyServiceProvider, EnvironmentValidation, PerformanceMonitoring
│   │   ├── database/migrations/
│   │   ├── routes/api.php, web.php
│   │   └── tests/
│   └── web/                    # React + Vite
│       ├── src/
│       │   ├── api/             # API clients
│       │   ├── components/     # AppLayout, AppSidebar, PageHeader, CropCycleScopeSelector, etc.
│       │   ├── config/         # permissions.ts, nav.ts, moduleKeys.ts, terminology.ts
│       │   ├── contexts/       # Auth, Modules, CropCycleScope (scope + localStorage)
│       │   ├── hooks/
│       │   ├── pages/
│       │   ├── types/
│       │   └── utils/
│       └── vite.config.ts
├── packages/shared/            # Shared TypeScript
│   └── src/
│       ├── api-client.ts
│       ├── types.ts
│       └── index.ts
├── docs/
│   ├── migrations.sql
│   ├── DELIVERY_SUMMARY.md
│   ├── FILE_TREE.md
│   ├── PHASE_*.md
│   └── QUICK_START.md
├── scripts/              # create-test-db.ps1, etc.
├── .gitignore
├── package.json          # workspaces: apps/*, packages/*; dev, build, e2e scripts
├── build.bat, build.ps1, build-and-start.bat
├── start-servers.bat, start-servers.ps1
├── setup-api.ps1, enable-php-extensions.ps1, enable-openssl.ps1
└── README.md (this file)
```

---

## Troubleshooting

| Issue | What to check |
|-------|----------------|
| **PHP/Composer not found** | PATH, `php --version`, `composer --version` |
| **OpenSSL / extension errors** | `enable-php-extensions.ps1`, uncomment `extension=openssl` in `php.ini`, restart Laragon |
| **DB connection failed** | `apps/api/.env`, Supabase/MySQL running, migrations/seed applied |
| **"No tenant selected"** | Tenant in `localStorage`, default `00000000-0000-0000-0000-000000000001` in DB |
| **Shared package errors** | `cd packages/shared && npm run build`; in `apps/web`: `npm install` |
| **CORS** | `apps/api/config/cors.php`; ensure origin matches frontend (e.g. `http://localhost:3000`) |
| **Port in use** | Free 8000 (API) and 3000 (web) or change in respective configs |
| **Playwright E2E fails / browsers missing** | From `apps/web` run `npx playwright install` once to install browsers. |
| **API slow / performance** | See [Performance monitoring](#performance-monitoring) below. |

### Performance monitoring

The API can log slow SQL queries and slow HTTP requests when enabled. Use this to track down performance issues.

**Steps:**

1. In `apps/api/.env`, enable one or both:
   ```env
   PERFORMANCE_SLOW_SQL_ENABLED=true
   PERFORMANCE_SLOW_SQL_THRESHOLD_MS=1000
   PERFORMANCE_SLOW_REQUEST_ENABLED=true
   PERFORMANCE_SLOW_REQUEST_THRESHOLD_MS=3000
   ```
2. (Optional) Adjust thresholds or truncation lengths: `PERFORMANCE_BINDINGS_MAX_LENGTH`, `PERFORMANCE_QUERY_PARAMS_MAX_LENGTH`.
3. Reproduce the slow behaviour, then check `apps/api/storage/logs/laravel.log`.

**Sample log lines:**

- Slow SQL:
  ```
  [timestamp] local.WARNING: Slow SQL query detected {"sql":"select * from \"parties\" where \"tenant_id\" = ?","bindings":["00000000-0000-0000-0000-000000000001"],"elapsed_ms":1250,"connection":"pgsql","tenant_id":"00000000-0000-0000-0000-000000000001"}
  ```
- Slow request:
  ```
  [timestamp] local.WARNING: Slow request detected {"method":"GET","path":"api/parties","status":200,"duration_ms":3500,"query_params":{"page":"1"},"tenant_id":"00000000-0000-0000-0000-000000000001"}
  ```

Bindings and query param values are truncated so sensitive data is not written in full. Disable with `PERFORMANCE_SLOW_SQL_ENABLED=false` and `PERFORMANCE_SLOW_REQUEST_ENABLED=false` when not needed.

---

## License

MIT
