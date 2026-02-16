# Farm ERP v2 — SaaS Accounting Core

A multi-tenant SaaS accounting and farm management system built as a monorepo: **Laravel API** (backend), **React + Vite** (frontend), and a shared **TypeScript** package. Supports Supabase Postgres (and optionally MySQL for local/Laragon).

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
- [Running the Application](#running-the-application)
- [API Overview](#api-overview)
- [Frontend Modules](#frontend-modules)
- [Development Scripts](#development-scripts)
- [Testing](#testing)
- [Project Structure](#project-structure)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

- **Multi-tenant SaaS** — Tenant isolation, platform admin, and tenant-level modules
- **Platform admin** — Tenant list, activate/suspend, minimal plan field (no billing), controlled impersonation with audit logging
- **Roles** — `platform_admin`, `tenant_admin`, `accountant`, `operator`
- **Land & Projects** — Land parcels, crop cycles (close/reopen with preview), land allocations (owner and Hari), projects, project rules
- **Land Leases (Maqada)** — Land leases per project/parcel/landlord, accruals (draft/post), posting to DUE_TO_LANDLORD and expense; reversal of posted accruals (new posting group, no mutation); **Landlord Statement** report (ledger-backed, read-only); traceability from accrual to posting group and reversal
- **Operational Transactions** — Draft/post workflow, posting groups, reversals
- **Treasury** — Payments (draft/post), advances, allocation preview and posting. **Apply payment to sales**: `GET payments/{id}/apply-sales/preview` (FIFO or manual), `POST payments/{id}/apply-sales`, `POST payments/{id}/unapply-sales`; creates/voids **sale_payment_allocations** (status ACTIVE/VOID). Payment posting creates **allocation rows** (type `PAYMENT`, scope `PARTY_ONLY`/`LANDLORD_ONLY`) so landlord statement and settlement views include them; posting group `source_type` is `PAYMENT`. Payment method determines credit account: **CASH** credits CASH, **BANK** credits BANK (system account `BANK` seeded). **Payment reversal**: `POST /api/payments/{id}/reverse` (posting_date, optional reason) creates a reversal posting group (negated ledger entries and allocation row); original posting group is immutable; payment stores `reversal_posting_group_id`, `reversed_at`, `reversed_by`, `reversal_reason`. Reversed payments are excluded from party balance/statement totals.
- **AR & Sales** — Sales documents with lines and inventory allocations, posting, reversals. **AR statement** per party (`GET parties/{id}/ar-statement`), **open sales** (`GET parties/{id}/receivables/open-sales`). Apply/unapply creates **SalePaymentAllocation** rows (ACTIVE/VOID); only ACTIVE (or null) allocations count toward open balance. **Reversal guards**: cannot reverse a payment or sale while it has ACTIVE allocations (409 until unapplied/reversed). **AR Aging report** (`GET /ar/aging?as_of=YYYY-MM-DD`): auditable, reconciles to open invoices; buckets per customer (current, 1_30, 31_60, 61_90, 90_plus) and grand totals; uses ACTIVE allocation sums only; excludes reversed sales. Sales margin reports.
- **Settlements** — Project-based and sales-based settlements with share rules, preview and posting, reversals
- **Settlement Pack (Governance)** — Per-project settlement pack generation (idempotent per version), summary totals, and full transaction register; DRAFT/FINAL status; re-generate when not final
- **Share Rules** — Configurable share rules for crop cycles, projects, and sales (margin or revenue basis)
- **Harvests** — Harvest tracking with lines, posting to inventory, production allocation, project association
- **Inventory** — Items, stores, UOMs, categories; GRNs, issues with allocation support (share rules, explicit percentages, project rules), transfers, adjustments; stock on-hand and movements
- **Labour** — Workers (Hari), work logs, wage accrual, wage payments
- **Machinery** — Machine management (with active/inactive status), work logs with meter tracking, rate cards (with activity type support), machinery charges, maintenance jobs and types, profitability reports; posting and reversals
- **Crop Operations** — Activity types, activities (inputs, labour); post consumes stock and accrues wages
- **Accounting Core** — Immutable ledger: `posting_groups` and `ledger_entries` are never updated or deleted; period locking enforced at posting time; **journal entries** (draft/post/reverse); **accounting periods** (create, close, reopen); **bank reconciliation** (create, statement lines, match/unmatch, finalize); **financial statements** from ledger only: **Profit & Loss** (income statement for date range, optional compare period) and **Balance Sheet** (as-of date, optional compare, equation check); accounts tenant-scoped with system accounts (AR/AP/CASH/BANK) and seeded chart; posting date cutoffs driven by `posting_groups.posting_date`
- **Accounting Guards** — Immutability protection for posted transactions, balanced posting validation
- **Audit Logs** — Transaction audit trail for posted operations
- **Reports** — Trial balance, **Profit & Loss** (income statement), **Balance Sheet** (with equation check), general ledger, project statement, project P&L, crop cycle P&L, account balances, cashbook, **AR ageing** (including auditable `GET /ar/aging` — open invoice balances per customer with bucket totals and grand totals), customer balances, AP ageing, supplier balances, AR/AP control reconciliation, yield reports, party ledger, party summary, **landlord statement** (Maqada, when `land_leases` module enabled), role ageing, crop cycle distribution, settlement statement, cost per unit, sales margin; reconciliation reports (project, crop-cycle, supplier AP); bank reconciliation; CSV export with Terrava-branded filenames; print-friendly layouts
- **Reconciliation** — Project settlement reconciliation, supplier AP reconciliation, reconciliation dashboard; ledger reconciliation for audit and debugging
- **Crop Cycle Close** — Close crop cycle with preview; crop-cycle-based settlements (preview and post); accounting corrections and guards
- **Dashboard** — Role-based dashboard with widgets, quick actions, onboarding panel for new users, empty states
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

- Seed data in `docs/migrations.sql` includes tenant:  
  `00000000-0000-0000-0000-000000000001`  
- The web app uses `X-Tenant-Id` (and/or auth); ensure this tenant exists when testing.

---

## Configuration

- **API env:** `apps/api/.env` — app key, DB, Supabase, etc.
- **Web:** `apps/web` reads from API; tenant and auth are applied via shared client / context.
- **CORS:** Laravel CORS is set for `http://localhost:3000`; adjust in `apps/api/config/cors.php` if needed.

---

## Running the Application

| Service | Command              | URL                |
|---------|----------------------|--------------------|
| API     | `cd apps/api && php artisan serve` | http://localhost:8000 |
| Web     | `cd apps/web && npm run dev`       | http://localhost:3000  |

Optional root scripts (if configured):

- `npm run dev:api`
- `npm run dev:web`

---

## API Overview

All tenant-scoped APIs use `X-Tenant-Id` (and/or auth). Role and module middleware apply as in `routes/api.php`.

| Area            | Examples                                                                 |
|-----------------|---------------------------------------------------------------------------|
| **Health**      | `GET /api/health`                                                        |
| **Auth**        | `POST /api/auth/login`                                                   |
| **Platform**    | `GET/POST /api/platform/tenants`, `GET/PUT /api/platform/tenants/{id}`; `GET /api/platform/impersonation`, `POST /api/platform/impersonation/start`, `POST /api/platform/impersonation/stop` (platform_admin only, audited) |
| **Dev**         | `GET/POST /api/dev/tenants`, `POST /api/dev/tenants/{id}/activate`        |
| **Users**       | `apiResource('users')`                                                   |
| **Parties**     | `apiResource('parties')`, `.../balances`, `.../statement`, `.../receivables/open-sales` |
| **Land**        | `apiResource('land-parcels')`, `.../documents`                           |
| **Land Leases** | `apiResource('land-leases')`; `GET/POST/PUT/DELETE /land-lease-accruals`, `POST /land-lease-accruals/{id}/post`, `POST /land-lease-accruals/{id}/reverse` (tenant_admin, `land_leases` module) |
| **Crop cycles** | `apiResource('crop-cycles')`, `.../close-preview`, `.../close`, `.../reopen`, `.../open` |
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
| **Reports**     | `trial-balance`, `profit-loss` (from/to, optional compare), `balance-sheet` (as_of, optional compare_as_of), `general-ledger`, `project-statement`, `project-pl`, `crop-cycle-pl`, `account-balances`, `cashbook`, `ar-ageing`, `GET ar/aging` (auditable AR aging), `customer-balances`, `customer-balance-detail`, `ap-ageing`, `supplier-balances`, `supplier-balance-detail`, `ar-control-reconciliation`, `ap-control-reconciliation`, `yield`, `party-ledger`, `party-summary`, `landlord-statement` (requires `land_leases`), `role-ageing`, `crop-cycle-distribution`, `settlement-statement`, `cost-per-unit`, `sales-margin`; `reports/reconciliation/project`, `reports/reconciliation/crop-cycle`, `reports/reconciliation/supplier-ap` |
| **Bank reconciliation** | `GET/POST bank-reconciliations`, `GET bank-reconciliations/{id}`, `POST .../clear`, `.../unclear`, `.../finalize`, `.../statement-lines`, `.../statement-lines/{lineId}/match`, `.../unmatch`, `.../void` |
| **Accounting**  | `GET/POST journals`, `GET/PUT journals/{id}`, `POST journals/{id}/post`, `POST journals/{id}/reverse`; `GET/POST accounting-periods`, `POST accounting-periods/{id}/close`, `POST .../reopen`, `GET .../events` |
| **Reconciliation** | `GET /reconciliation/project/{id}`, `GET /reconciliation/supplier/{party_id}` |
| **Settings**    | `GET/PUT /settings/tenant`; `tenant/modules`; `tenant/farm-profile` (GET → `{exists,farm}`, POST create, PUT update); `tenant/users` |

Exact routes, methods, and middleware are in `apps/api/routes/api.php`.

---

## Frontend Modules

The web app includes pages (and routes) for:

- **Dashboard**, **Health**
- **Daily book entries**, **Operational transactions**, **Posting group detail** (`/app/posting-groups/:id`)
- **Parties**, **Sales**, **Payments** (list, detail with Post and **Reverse** for posted payments; reverse modal: posting date, reason), **Advances**
- **Land parcels**, **Land leases** (when `land_leases` module enabled: list, detail with accruals, post/reverse accruals, view posting group and reversal), **Land allocations**, **Crop cycles** (with close/reopen and detail), **Projects**, **Project rules**, **Share rules**, **Settlements** (project-based, sales-based, crop-cycle-based), **Settlement Pack** (view pack at `/app/settlement-packs/:id` with summary, transaction register, re-generate when not FINAL; generate from Settlement page), **Harvests**
- **Inventory:** items, stores, categories, UOMs, GRNs, issues (with allocation configuration), transfers, adjustments, stock on-hand, movements (Back + breadcrumbs on internal pages)
- **Labour:** workers, work logs, payables outstanding (when module enabled)
- **Machinery:** machines, work logs, rate cards, charges, maintenance jobs and types, profitability reports (when `machinery` module enabled)
- **Crop Operations:** activity types, activities (inputs, labour), timeline (when `crop_ops` enabled)
- **Reports:** trial balance, **Profit & Loss**, **Balance Sheet**, general ledger, project statement, project P&L, crop cycle P&L, account balances, cashbook, AR ageing, customer balances, AP ageing, supplier balances, yield reports, sales margin, party ledger, party summary, **landlord statement** (when `land_leases` enabled), role ageing, crop cycle distribution, settlement statement; reconciliation dashboard; **bank reconciliation** (list and detail: statement lines, match/unmatch, finalize); CSV export functionality; print-friendly layouts
- **Dashboard:** role-based widgets, quick actions, onboarding panel, empty states
- **Settings:** tenant, modules, farm profile (admin), users (admin), localisation
- **Platform (platform_admin only):** tenant list at `/app/platform/tenants` (status badge, suspend/activate, plan dropdown, impersonate); impersonation banner in tenant app with “Impersonating: {tenant}” and exit; tenant detail with plan and impersonate

Access to some areas is gated by **roles** and **tenant modules** (e.g. `land`, `land_leases`, `inventory`, `labour`, `machinery`, `crop_ops`, `ar_sales`, `treasury_payments`, `treasury_advances`, `settlements`, `reports`).

---

## Development Scripts

| Script / command       | Purpose                                    |
|------------------------|--------------------------------------------|
| `enable-php-extensions.ps1` | Enable OpenSSL, fileinfo for Laravel |
| `build.bat` / `build.ps1`   | Full build (env, composer, shared, web, migrate, frontend build) |
| `build-and-start.bat`      | Build and start API + web                  |
| `start-servers.bat` / `start-servers.ps1` | Start API and web              |
| `setup-api.ps1`            | API .env and composer setup               |
| `scripts/create-test-db.ps1` | Create test DB (see script for target)  |
| `npm run build`             | Build shared package + web app            |
| `npm run e2e`               | Start API then run Playwright E2E (uses `E2E_PROFILE` or default) |
| `npm run e2e:core`          | E2E with profile `core`                   |
| `npm run e2e:all`           | E2E with profile `all` (e.g. platform admin flows) |

---

## Testing

### Backend (Laravel)

```bash
cd apps/api
php artisan test
```

Covers tenant isolation, CRUD, validation, platform admin (tenant list, suspend/activate, impersonation gating and audit), and other feature tests. To run only platform admin and impersonation tests:

```bash
php artisan test --filter=PlatformAdminTenantAndImpersonation
```

Tests expect PostgreSQL (see `apps/api/tests/README.md`). Create the test DB once (e.g. `scripts/create-test-db.ps1` on Windows). Feature tests include **Settlement Pack** (generate returns expected shape/totals, GET returns register rows, tenant isolation, idempotency), **Land Lease accrual posting and reversal** (post creates PG/ledger, reverse creates reversal PG and negates entries, idempotent second reverse, tenant isolation), **Landlord Statement** (ledger-backed report, opening/closing balance, lines ordered by date), **Payments** (posting creates posting group with `source_type=PAYMENT`, allocation row `PAYMENT`, ledger entries; method BANK credits BANK account; reverse creates reversal posting group and negated allocation row; cannot reverse twice), **Payment apply/unapply** (PaymentApplySalesTest: preview FIFO, apply FIFO/manual, unapply voids allocations, reversed sale/payment excluded from open sales), **Reversal guards** (ReversalGuardsTest: cannot reverse payment or sale while ACTIVE allocations exist; 409 until unapplied), **AR Statement** (ARStatementTest), **AR Aging report** (ARAgingReportTest: buckets per customer and grand totals, apply payment reduces open balance, unapply restores it, reversed sale excluded, default as_of), **Financial Statements** (FinancialStatementsTest: Profit & Loss for range with income/expense totals and net profit, Balance Sheet as-of with equation check, compare period deltas, tenant isolation), **Accounting period locking** (AccountingPeriodLockingTest), and **Bank reconciliation** (BankReconciliationTest, BankStatementLinesTest):

```bash
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
```

### Frontend E2E (Playwright)

Playwright specs live under `apps/web/playwright/`. Run from repo root:

```bash
npm run e2e          # starts API, then runs E2E (default profile)
npm run e2e:core     # E2E with profile "core"
npm run e2e:all      # E2E with profile "all" (includes platform admin)
```

Platform admin flows (tenant list, impersonation) require a profile that logs in as `platform_admin` (e.g. set `E2E_PROFILE=all` or use seed that creates a platform admin). Spec: `apps/web/playwright/specs/15_platform_tenant_impersonation.spec.ts`.

---

## Project Structure

```
.
├── apps/
│   ├── api/                    # Laravel API
│   │   ├── app/Domains/        # Domain logic (e.g. Accounting/Reports/FinancialStatementsService, Governance/SettlementPack)
│   │   ├── app/Http/Controllers/
│   │   ├── app/Http/Middleware/
│   │   ├── app/Models/
│   │   ├── database/migrations/
│   │   ├── routes/api.php, web.php
│   │   └── tests/
│   └── web/                    # React + Vite
│       ├── src/
│       │   ├── api/             # API clients
│       │   ├── components/
│       │   ├── contexts/
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
├── scripts/
├── .gitignore
├── package.json
├── build.bat, build.ps1
├── setup-api.ps1
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
