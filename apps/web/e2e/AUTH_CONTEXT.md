# E2E Auth Context (localStorage and API headers)

This document records the **exact** keys and flow used by the Farm ERP web app so Playwright can set the same context.

## localStorage keys (do not invent; use these)

| Key | Purpose | API header |
|-----|---------|------------|
| `farm_erp_tenant_id` | Current tenant | `X-Tenant-Id` |
| `farm_erp_user_role` | Current user role | `X-User-Role` |
| `farm_erp_user_id` | Current user id | `X-User-Id` |

## Where they are set in the app

- **useAuth.ts** (`src/hooks/useAuth.ts`): Sets all three after `/api/auth/me` or from dev fallback. Clears on logout.
- **TenantSelector.tsx**: Sets `farm_erp_tenant_id` when user selects a tenant.
- **LoginPage.tsx**: On "Continue", sets tenant via `setTenantId` (which writes `farm_erp_tenant_id`) and role via `setUserRole` (which writes `farm_erp_user_role`). Does not set `farm_erp_user_id` in dev flow (optional for E2E; API may accept without it in dev).

## API client

The shared **api-client** (`packages/shared/src/api-client.ts`) reads these keys and sends:

- `X-Tenant-Id` from `farm_erp_tenant_id` (not sent for platform routes)
- `X-User-Role` from `farm_erp_user_role`
- `X-User-Id` from `farm_erp_user_id`

## Dev login flow (current app)

- No email/password on the login page.
- User selects a **tenant** (from table: click "Select" on a row) and a **role** (dropdown `#role`), then clicks **Continue**.
- App then sets `farm_erp_tenant_id` and `farm_erp_user_role` and navigates to `/app/dashboard` or `/app/platform/tenants` (for platform_admin).
- For E2E we can either perform this UI flow or set the keys directly before navigating to the app (e.g. after one UI login, or in a fixture).
