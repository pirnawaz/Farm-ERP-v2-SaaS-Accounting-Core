# Running E2E tests (Playwright)

Playwright E2E tests live in `apps/web/e2e/` and run against the Farm ERP web app and API.

## For Accountants / Reviewers

The E2E suite **verifies that core accounting rules are respected** in the application: draft vs posted state, posting into open crop cycles only, posting groups and ledger artifacts, and reversals. When all tests pass (green), you can be confident that these invariants hold.

**What is verified (examples):**
- Draft transactions stay editable and show no posting group until posted.
- Posting requires a valid date and creates a posting group and ledger entries.
- Posting into a **closed** crop cycle is rejected (error message; status stays DRAFT).
- Reversals create a new posting group and leave the original immutable.

**When a test fails:** Playwright captures **screenshots and videos** for the failing step. After the run, open the HTML report to see exactly what the app showed when the assertion failed:

```bash
npm run e2e:report
```

**Commands you need:**
- Run all E2E tests: `npm run e2e`
- Open the last HTML report (screenshots, traces, videos): `npm run e2e:report`
- Run tests then open the report: `npm run e2e:full`

## Install

From the **monorepo root**:

```bash
npm install
npx -w apps/web playwright install --with-deps
```

Or from `apps/web`:

```bash
npm install
npx playwright install --with-deps
```

Copy env and set values as needed:

```bash
cp apps/web/.env.e2e.example apps/web/.env.e2e
# Edit apps/web/.env.e2e: BASE_URL, API_URL, DEFAULT_TENANT_ID, etc.
```

## Run

From the **root** (recommended):

```bash
npm run e2e
```

From `apps/web`:

```bash
npm run e2e
```

Other scripts:

- `npm run e2e:ui` – open Playwright UI
- `npm run e2e:headed` – run in headed browser
- `npm run e2e:report` – open the last HTML report
- `npm run e2e:full` – run E2E then open the report (from repo root)

## Report

After a run, open the HTML report:

```bash
npm run e2e:report
```

Reports are under `apps/web/playwright-report/` (and `test-results/` for traces/screenshots/videos).

## Prerequisites

- **Web app** running at `BASE_URL` (default `http://localhost:3000`).
- **API** running at `API_URL` (default `http://localhost:8000`).
- For **deterministic E2E**: API must have `APP_DEBUG=true` so dev routes are enabled. `globalSetup` calls `POST /api/dev/e2e/seed` (unauthenticated); the API seeds an E2E tenant, OPEN crop cycle, project, and operational records (DRAFT, POSTED, reversal-ready), then returns IDs. `globalSetup` writes these to `apps/web/e2e/.seed-state.json` (this file is gitignored). Tests that depend on seed data (e.g. `30-accounting-core.spec.ts`) read `.seed-state.json` and skip with a clear message if the file is missing (e.g. seed failed or API was down).

## Adding data-testid hooks

Tests use `data-testid` where possible for stability. Required hooks are listed in:

- `e2e/SELECTORS_TODO.md`

When adding or changing UI that E2E covers:

1. Add the suggested `data-testid` from `SELECTORS_TODO.md` to the component.
2. Use the same selector in the spec (e.g. `page.locator('[data-testid=post-btn]')`).
3. Update `SELECTORS_TODO.md` if you introduce new selectors.

## Deterministic seed (E2E)

- **Requirement**: API running with `APP_DEBUG=true` so `POST /api/dev/e2e/seed` returns 200 (dev-only; 403 in prod or when `APP_DEBUG=false`).
- **globalSetup** runs once before tests: it calls `POST /api/dev/e2e/seed` with optional `tenant_id` and `tenant_name` (default `"E2E Farm"`). On success it writes the response (tenant, crop cycle, project, draft/posted/reversal IDs) to `apps/web/e2e/.seed-state.json`. This file is **ignored by git**.
- **Tests** that need deterministic data use `readSeedState()` from `e2e/helpers/seed.ts`; if the file is missing they call `test.skip(...)` with a message. Use `requireSeedState()` when the test must have seed (it throws with a helpful message).
- **Idempotency**: The backend seed is idempotent: multiple runs reuse the same tenant, cycle, project, and seeded records where possible (stored in DB table `e2e_seed_state`). Running `npm run e2e` from the repo root with API and web app up gives a reliable, deterministic run.

## Dev-mode auth bypass (loginDev)

In dev mode, E2E **bypasses UI login** for stability: tests use the `loginDev()` helper, which injects auth context into **localStorage** (`farm_erp_tenant_id`, `farm_erp_user_role`, `farm_erp_user_id`), reloads, then navigates to `/app` and asserts the app shell is visible. This avoids flakiness from the login form until the auth UX is finalized. **UI login tests** will be added later in a dedicated `auth.spec.ts` once the login flow is stable for automation.

## Known TODOs

- **globalSetup**: If the API is not running or returns 403 (e.g. production or `APP_DEBUG=false`), globalSetup does not write `.seed-state.json`. Accounting-core and any tests that call `readSeedState()` will skip. Ensure API is up with `APP_DEBUG=true` and `API_URL` is set for a full deterministic run.
- **Toasts**: Success/error toasts use fallback selectors (e.g. `.toast`) if the app does not set `data-testid=toast-success` / `data-testid=toast-error` on the toast container.
- **Email/password login**: The app currently uses dev login (tenant + role). `loginViaUI` in `e2e/helpers/auth.ts` is kept for future use; E2E uses `loginDev()` (localStorage injection) until the login UX is stable. When real auth is used, UI login tests will live in a dedicated `auth.spec.ts`.
