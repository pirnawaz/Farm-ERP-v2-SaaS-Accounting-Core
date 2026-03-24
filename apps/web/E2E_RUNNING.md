# Running E2E (Playwright)

## Prerequisites

- **API must be running** and reachable at `API_BASE_URL` (default `http://localhost:8000`). Global-setup calls `POST /api/dev/e2e/seed` and `POST /api/dev/e2e/auth-cookie`; specs that use storage state (e.g. 50_role_accountant, 51_role_operator) need the API for tenant modules and data. Specs 52 (tenant login), 53 (select-tenant), and 54 (accept-invite) perform real login or invite flows and also require the API.
- Start the API (e.g. `cd apps/api && php artisan serve`) before running Playwright, or use your usual dev stack.

## Force-all-modules flag (TEMP)

For system completion and Playwright testing we support a **temporary** override that makes all modules appear enabled for all tenants and bypasses module enforcement. This simplifies development and keeps E2E stable (e.g. `modules-ready` reaches `ready` immediately).

**This is TEMP and will be removed later.** To revert, search for `TEMP` and `FORCE_ALL_MODULES_ENABLED` in the codebase.

### How to enable

- **API (Laravel):** set in `.env` or environment:
  - `FORCE_ALL_MODULES_ENABLED=true`
- **Web (Vite):** set when starting the dev server (e.g. in `.env` or when running the E2E dev command):
  - `VITE_FORCE_ALL_MODULES_ENABLED=true`

For local dev and CI you typically set both so that the API returns all modules as enabled and the frontend shows everything as ready without waiting on the modules API.

### When the flag is off

When `FORCE_ALL_MODULES_ENABLED` / `VITE_FORCE_ALL_MODULES_ENABLED` is false or unset, the normal module system applies: only core and explicitly enabled (plus dependency) modules are effective, and `require_module` middleware enforces access.

## Dev-mode auth performance flags

- **Default behaviour:** In the dev-mode “select farm + role then continue” flow, the app uses the identity stored in localStorage (`farm_erp_user_role`, `farm_erp_tenant_id`, `farm_erp_user_id`) and **does not** call `/api/auth/me` on load when that identity is already present. This avoids repeated 401s and keeps the dashboard responsive.
- **Optional verification:** Set `VITE_VERIFY_COOKIE_AUTH_IN_DEV=true` in the web app env if you use cookie-based auth in dev and want to verify it once in the background. The app will call `/api/auth/me` once (no retries). For most local dev and Playwright, leave this **false** or unset.
