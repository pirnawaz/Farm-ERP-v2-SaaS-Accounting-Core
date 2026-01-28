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
- **Roles** — `platform_admin`, `tenant_admin`, `accountant`, `operator`
- **Land & Projects** — Land parcels, crop cycles, land allocations (owner and Hari), projects, project rules
- **Operational Transactions** — Draft/post workflow, posting groups, reversals
- **Treasury** — Payments, advances, allocation preview and posting
- **AR & Sales** — Sales documents with lines and inventory allocations, posting, reversals, AR ageing, sales margin reports
- **Settlements** — Project-based and sales-based settlements with share rules, preview and posting, reversals
- **Share Rules** — Configurable share rules for crop cycles, projects, and sales (margin or revenue basis)
- **Harvests** — Harvest tracking with lines, posting to inventory, production allocation
- **Inventory** — Items, stores, UOMs, categories; GRNs, issues with allocation support (share rules, explicit percentages, project rules), transfers, adjustments; stock on-hand and movements
- **Labour** — Workers (Hari), work logs, wage accrual, wage payments
- **Machinery** — Machine management, work logs with meter tracking, rate cards, machinery charges, maintenance jobs and types, profitability reports; posting and reversals
- **Crop Operations** — Activity types, activities (inputs, labour); post consumes stock and accrues wages
- **Accounting Guards** — Immutability protection for posted transactions, balanced posting validation
- **Audit Logs** — Transaction audit trail for posted operations
- **Reports** — Trial balance, general ledger, project statement, project P&L, crop cycle P&L, account balances, cashbook, AR ageing, yield reports; CSV export with Terrava-branded filenames; print-friendly layouts
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
| **Platform**    | `GET/POST /api/platform/tenants`, `GET/PUT /api/platform/tenants/{id}`   |
| **Dev**         | `GET/POST /api/dev/tenants`, `POST /api/dev/tenants/{id}/activate`        |
| **Users**       | `apiResource('users')`                                                   |
| **Parties**     | `apiResource('parties')`, `.../balances`, `.../statement`, `.../receivables/open-sales` |
| **Land**        | `apiResource('land-parcels')`, `.../documents`                           |
| **Crop cycles** | `apiResource('crop-cycles')`, `.../close`, `.../open`                    |
| **Land allocations** | `apiResource('land-allocations')`                                   |
| **Projects**    | `apiResource('projects')`, `POST /projects/from-allocation`              |
| **Project rules**| `GET/PUT /projects/{id}/rules`                                           |
| **Share rules**  | `apiResource('share-rules')`                                            |
| **Operational transactions** | `apiResource('operational-transactions')`, `POST .../post`       |
| **Settlement**  | `POST /projects/{id}/settlement/preview`, `.../offset-preview`, `.../post`; `GET/POST /settlements`, `GET /settlements/preview`, `POST /settlements/{id}/post`, `POST /settlements/{id}/reverse` |
| **Payments**    | `apiResource('payments')`, `.../allocation-preview`, `.../post`           |
| **Advances**    | `apiResource('advances')`, `.../post`                                    |
| **Sales**       | `apiResource('sales')`, `.../post`, `.../reverse`                      |
| **Inventory**   | Items, stores, UOMs, categories; GRNs, issues, transfers, adjustments; `.../post`, `.../reverse`; `stock/on-hand`, `stock/movements` |
| **Labour**      | `v1/labour/workers`, `v1/labour/work-logs` (CRUD, `.../post`, `.../reverse`); `v1/labour/payables/outstanding` |
| **Crop Ops**    | `v1/crop-ops/activity-types` (CRUD); `v1/crop-ops/activities` (timeline, CRUD, `.../post`, `.../reverse`); `v1/crop-ops/harvests` (CRUD, lines, `.../post`, `.../reverse`) |
| **Machinery**   | `v1/machinery/machines` (CRUD); `v1/machinery/maintenance-types` (CRUD); `v1/machinery/work-logs` (CRUD, `.../post`, `.../reverse`); `v1/machinery/rate-cards` (CRUD); `v1/machinery/charges` (list, show); `v1/machinery/maintenance-jobs` (CRUD, `.../post`, `.../reverse`); `v1/machinery/reports/profitability` |
| **Posting groups** | `GET /posting-groups/{id}`, `.../ledger-entries`, `.../allocation-rows`, `.../reverse`, `.../reversals` |
| **Reports**     | `trial-balance`, `general-ledger`, `project-statement`, `project-pl`, `crop-cycle-pl`, `account-balances`, `cashbook`, `ar-ageing`, `yield` |
| **Settings**    | `GET/PUT /settings/tenant`; `tenant/modules`; `tenant/farm-profile` (GET → `{exists,farm}`, POST create, PUT update); `tenant/users` |

Exact routes, methods, and middleware are in `apps/api/routes/api.php`.

---

## Frontend Modules

The web app includes pages (and routes) for:

- **Dashboard**, **Health**
- **Daily book entries**, **Operational transactions**
- **Parties**, **Sales**, **Payments**, **Advances**
- **Land parcels**, **Land allocations**, **Crop cycles**, **Projects**, **Project rules**, **Share rules**, **Settlements** (project-based and sales-based), **Harvests**
- **Inventory:** items, stores, categories, UOMs, GRNs, issues (with allocation configuration), transfers, adjustments, stock on-hand, movements (Back + breadcrumbs on internal pages)
- **Labour:** workers, work logs, payables outstanding (when module enabled)
- **Machinery:** machines, work logs, rate cards, charges, maintenance jobs and types, profitability reports (when `machinery` module enabled)
- **Crop Operations:** activity types, activities (inputs, labour), timeline (when `crop_ops` enabled)
- **Reports:** trial balance, general ledger, project statement, project P&L, crop cycle P&L, account balances, cashbook, AR ageing, yield reports, sales margin; CSV export functionality; print-friendly layouts
- **Dashboard:** role-based widgets, quick actions, onboarding panel, empty states
- **Settings:** tenant, modules, farm profile (admin), users (admin), localisation
- **Platform:** tenants (platform admin)

Access to some areas is gated by **roles** and **tenant modules** (e.g. `land`, `inventory`, `labour`, `machinery`, `crop_ops`, `ar_sales`, `treasury_payments`, `treasury_advances`, `settlements`, `reports`).

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

---

## Testing

```bash
cd apps/api
php artisan test
```

Covers tenant isolation, CRUD, validation, and other feature tests.

---

## Project Structure

```
.
├── apps/
│   ├── api/                    # Laravel API
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

---

## License

MIT
