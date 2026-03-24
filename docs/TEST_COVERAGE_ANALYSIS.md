# Test Coverage Analysis & Recommendations

**Date:** March 2025  
**Scope:** All existing tests (unit, integration, e2e), role-based behavior, test user setup, use case coverage.  
**Goal:** Production-grade confidence before release.

---

## 1. Executive Summary

- **Failing tests:** One API test currently fails: `ARStatementTest::ar_statement_excludes_reversed_payment_in` (expects 201, receives 409 on payment reverse).
- **Role coverage:** Backend role enforcement is well covered by `RolePermissionEnforcementTest` and scattered feature tests; **E2E and frontend run almost entirely as a single role** (tenant_admin or platform_admin). Accountant and operator are not exercised in Playwright.
- **Test user setup:** E2E seed creates one user per role (tenant_admin, accountant, operator, platform_admin), but **global-setup only logs in as tenant_admin or platform_admin**. No dedicated accountant/operator E2E runs. **Potential bug:** `E2ESeedService::ensureUsersPerRole` creates a `platform_admin` user with `tenant_id => $tenantId`, which violates `User` model rules (platform_admin must have `tenant_id` null); this may throw when seed runs or rely on a different auth path.
- **Use case coverage:** Core flows (land → cycle → allocation → project → transaction → post) and module gating are covered. Gaps: role-specific UI/API paths, tenant login flow in E2E, accountant vs operator permission boundaries in E2E, and some edge cases (e.g. payment reverse when allocations exist).

---

## 2. Existing Tests Overview

### 2.1 Backend (API) – Laravel Feature Tests

| Location | Count | Notes |
|----------|--------|------|
| `apps/api/tests/Feature/*.php` | 129 files | Broad coverage: posting, reversals, reports, tenant isolation, module licensing, auth, impersonation, invitations. |

**Strengths:**

- Role permission enforcement: `RolePermissionEnforcementTest` (accountant cannot manage users/modules; operator cannot post/reverse GRN; platform vs tenant isolation).
- Auth: `UnifiedAuthLoginTest` (platform, single-tenant, multi-tenant select, select-tenant, disabled identity), `PlatformAuthLoginTest` (platform login accept/reject).
- Module gating: `LabourModuleDisabledReturns403Test`, `InventoryModuleDisabledReturns403Test`, `CropOpsModuleDisabledReturns403Test`, `ModuleLicensingEnforcementTest`, `TenantAddonModulesTest`.
- Posting/reversal: Many tests for idempotency, guards, and ledger correctness.

### 2.2 Frontend – Unit Tests (Vitest)

| File | What it covers |
|------|-----------------|
| `ProtectedRoute.test.tsx` | Redirect when unauthenticated; render children when authenticated (tenant_admin). |
| `LoginPage.test.tsx` | Login form and role dropdown (no real login or role-specific behavior). |
| `ErrorBoundary.test.tsx` | Error boundary behavior. |
| `SaleDetailPage.test.tsx` | Sale detail page (likely rendering / basic behavior). |
| `MachineryServicesPage.test.tsx` | Machinery services page. |
| `bankReconciliation.test.ts` | Bank reconciliation API/helpers. |

**Gaps:**

- No unit tests for `useRole` / `useAuth` with different roles (accountant, operator).
- No tests for role-based UI (e.g. Post button hidden for operator, Settings only for tenant_admin).
- No tests for permissions config (`permissions.ts`) or permission-based components.
- Login tests do not assert platform vs tenant login paths or role selection outcome.

### 2.3 E2E – Playwright

| Specs | Profile | Auth used |
|-------|---------|-----------|
| Core (00, 10, 11, 99, etc.) | `E2E_PROFILE=core` | tenant_admin (cookie + localStorage) |
| All (20, 30–41, 15, 16) | `E2E_PROFILE=all` | platform_admin for platform specs; tenant_admin for tenant specs |
| 16_platform_login_and_modules | — | Uses fresh login (no auth state) for platform admin login only |

**Observations:**

- All tenant E2E runs use **one** role (tenant_admin or platform_admin). No Playwright project or setup runs as accountant or operator.
- Login flow E2E: only platform admin login is tested (16); **tenant login (unified or select-tenant) is not E2E’d**.
- Module gating E2E: 99_guards (core profile) and 16 (module toggle 403→200→403) cover backend and nav; good alignment with backend.

---

## 3. Failing Tests & Alignment

### 3.1 Failing Test

**Test:** `Tests\Feature\ARStatementTest::ar_statement_excludes_reversed_payment_in`

- **Expectation:** `POST /api/payments/{$payment->id}/reverse` returns **201**.
- **Actual:** **409** (Conflict).
- **Likely cause:** API now returns 409 when reversing a payment that has allocations (or other business rule). Test was written when reverse returned 201 regardless.
- **Recommendation:** Either (a) adjust the test to match current API contract (e.g. assert 409 when payment has allocations and document it), or (b) change the scenario so the payment has no allocations before reverse and keep 201; or (c) fix the API if 201 is still the intended behavior for this case.

### 3.2 Tests That May No Longer Align With Updated Logic

- **Unified auth vs legacy User:** Many API tests still use `X-User-Role` / `X-User-Id` / `X-Tenant-Id` (dev identity). Production uses Identity + TenantMembership + cookie. Ensure all auth paths (unified login, select-tenant, platform login) and middleware (ResolveTenantAuth, ResolvePlatformAuth) are covered; `UnifiedAuthLoginTest` already covers key flows.
- **E2E global-setup:** Writes `farm_erp_user_role` and `farm_erp_user_id` to localStorage and uses dev auth cookie. If the app has moved to Identity-only for tenant auth, confirm that E2E still gets a valid session (e.g. via dev cookie that sets the same claims).
- **ProtectedRoute.test.tsx:** Only checks “has role” vs “no role”. It does not test that a given role cannot access a route that another role can (e.g. operator vs tenant_admin).

### 3.3 Areas of Modified Code Not Covered by Tests

- **Identity / TenantMembership:** If recent work added Identity and TenantMembership, ensure there are feature tests for: login with membership, multi-tenant list, select-tenant, and that tenant-scoped APIs use the selected membership (and that disabling membership blocks access).
- **Frontend role-based UI:** Many pages use `hasRole(['tenant_admin', 'accountant'])` or similar for Post/Edit/Settings. These branches are not unit-tested; only E2E as tenant_admin exercises them.
- **Payment reverse with allocations:** The ARStatementTest failure suggests payment reverse behavior (or its contract) may have changed. Any new “reverse only when no allocations” (or similar) rule should be covered by a dedicated test and documented.

---

## 4. Role-Based Behavior

### 4.1 Roles (Backend & Frontend)

| Role | Backend | Frontend |
|------|---------|----------|
| **platform_admin** | Platform routes, impersonation, audit logs | Platform nav, tenant list, module toggles per tenant |
| **tenant_admin** | All tenant routes, user/module management, close cycle | Settings, Users, Modules, Onboarding, Post/Reverse, all create/edit |
| **accountant** | Post/reverse, reports, dashboard; no user/module management | Post/Reverse, reports, dashboard; no Settings/Users/Modules |
| **operator** | Create/edit operational data; no post/reverse | Create/edit; Post/Reverse buttons hidden |

### 4.2 What Is Tested Today

- **API:** `RolePermissionEnforcementTest` covers: accountant cannot users/modules/platform; operator cannot post/reverse GRN; tenant_admin can users + modules; platform_admin can platform/tenants. Other feature tests use accountant or operator where relevant (e.g. ARStatementTest, MachineControllerTest).
- **Login per role:** Unified auth returns `mode` and `user.role`; platform login rejects non–platform_admin. No E2E that logs in as accountant or operator and asserts UI/API.
- **Frontend:** No unit tests that set different `userRole` and assert visibility of Post, Settings, or nav items.

### 4.3 Missing Role-Specific Coverage

1. **E2E as accountant:** Login as accountant (or switch to accountant user in E2E), then:  
   - Can open dashboard, reports, transactions, post/reverse where allowed.  
   - Cannot open Settings, Users, Modules; cannot close crop cycle.  
   - Nav and action buttons match (no Settings link, no Post where operator-only).
2. **E2E as operator:** Login as operator, then:  
   - Can create/edit transactions, work logs, etc.  
   - Cannot post or reverse; Post/Reverse buttons absent or disabled.  
   - Cannot access Settings, Users, Modules, crop cycle close.
3. **Unit tests for role-based UI:** For pages that use `hasRole()` (e.g. SaleDetailPage, InvGrnDetailPage, DashboardPage), render with different `userRole` (tenant_admin, accountant, operator) and assert presence/absence of Post, Reverse, Edit, Settings links.
4. **Permission matrix:** Consider a single test (unit or API) that, for each role, checks the set of allowed permissions (or routes) against `permissions.ts` / backend middleware so that frontend and backend stay in sync.

---

## 5. Test User Setup

### 5.1 Current Setup

- **API tests:** Use in-test creation (RefreshDatabase) and headers (`X-Tenant-Id`, `X-User-Role`, `X-User-Id`). No shared fixtures; some traits (e.g. `MakesAuthenticatedRequests`) for cookie auth.
- **E2E:**  
  - `POST /api/dev/e2e/seed` creates tenant + users: one per role (`e2e-tenant_admin@e2e.local`, `e2e-accountant@e2e.local`, `e2e-operator@e2e.local`, `e2e-platform_admin@e2e.local`).  
  - `POST /api/dev/e2e/auth-cookie` logs in as one user (tenant_id, role, user_id).  
  - Global-setup only calls auth-cookie for **tenant_admin** (core) or **platform_admin** (all).  
  - Seed returns `accountant_user_id` and `operator_user_id`, but they are never used for E2E login.

### 5.2 E2E Seed and platform_admin

- `E2ESeedService::ensureUsersPerRole` creates a user with `role => 'platform_admin'` and `tenant_id => $tenantId`.  
- `User::booted()` requires: if `role === 'platform_admin'` then `tenant_id` must be null.  
- So creating platform_admin with a non-null tenant_id can throw. Either:  
  - platform_admin is created elsewhere with `tenant_id = null`, or  
  - the seed is never run in a way that creates platform_admin in that loop, or  
  - there is a different auth model for E2E (e.g. Identity-only) and the User row is not used for platform_admin.  
- **Recommendation:** Create platform_admin in seed with `tenant_id => null` in a separate step; do not add platform_admin to the same loop as tenant-scoped roles.

### 5.3 Recommended: Role-Based E2E Fixtures

- **Option A – Multiple Playwright projects:**  
  - In `playwright.config.ts`, define projects that use different storage states (e.g. `tenant_admin`, `accountant`, `operator`).  
  - Global-setup (or a script) runs seed once, then calls auth-cookie for each role and saves `playwright/.auth/tenant_admin.json`, `accountant.json`, `operator.json`, and optionally `platform_admin.json`.  
  - Each project uses `storageState: 'playwright/.auth/tenant_admin.json'` (or the other roles).  
  - Tag specs that need a specific role (e.g. `@as-accountant`) and run only the matching project.

- **Option B – Single run, switch user in test:**  
  - Seed creates all users; one E2E run uses tenant_admin.  
  - For “as accountant” / “as operator” tests, call a small helper (e.g. `POST /api/dev/e2e/auth-cookie` with accountant/operator user_id and role, then reload context or set localStorage and cookie) and then run the test steps.  
  - Less isolation than separate projects but avoids multiple full runs.

- **Factory for API tests:**  
  - Add a `UserFactory` or trait that creates tenant + users per role (tenant_admin, accountant, operator) and optionally platform_admin (with tenant_id null).  
  - Use in RolePermissionEnforcementTest and any new role-scoped tests so role setup is consistent and scalable.

---

## 6. Use Case Coverage

### 6.1 Major Flows and Recent Changes

- **Core journey (land → cycle → allocation → project → transaction → post):** Covered by `10_core_full_journey.spec.ts` and API tests.  
- **Module enable/disable:** Backend 403 when disabled; E2E core profile hides optional nav and 99_guards asserts 403; 16_platform_login_and_modules tests module toggle and API 403→200→403.  
- **Platform admin:** Login (16), tenant list, tenant detail, module toggles, plan change, audit logs; API tests for platform and impersonation.  
- **Unified login:** API only (UnifiedAuthLoginTest); no E2E for tenant login or select-tenant.  
- **Invitations:** API (UserInvitationTest); no E2E for invite flow or accept-invite.  
- **Impersonation:** API (PlatformAdminTenantAndImpersonationTest); E2E 15_platform_tenant_impersonation.

### 6.2 Missing or Weak Coverage

1. **Tenant login (E2E):** User enters email/password on tenant login, gets `mode: tenant` or `mode: select_tenant`; if select_tenant, chooses tenant and then lands on dashboard. No Playwright spec for this.
2. **Select-tenant (E2E):** Multi-tenant user selects tenant and is scoped to that tenant; no E2E.
3. **Invitation accept (E2E):** User clicks invite link, sets password, lands in app; no E2E.
4. **Payment reverse with allocations:** ARStatementTest fails (409). Add an explicit test: “payment with allocations cannot be reversed (409)” or “reversing payment without allocations returns 201”, and align test and API.
5. **Operator cannot post (E2E):** Operator user opens a GRN or work log; Post button absent or disabled; if they call post API directly (e.g. via request context), expect 403.
6. **Accountant cannot access Settings (E2E):** Accountant user; Settings/Users/Modules not in nav or return 403; dashboard and reports work.
7. **Report visibility by role:** If any report is restricted by role, add API and/or E2E tests.
8. **Land lease / accruals:** Covered in 30_land_leases.spec.ts (when profile=all); ensure API has equivalent feature tests for accrual post/reverse and role (tenant_admin/accountant only if applicable).

---

## 7. Gaps Summary

| Category | Gap |
|----------|-----|
| **Failing** | ARStatementTest: payment reverse expects 201, gets 409. |
| **Roles** | E2E runs only as tenant_admin or platform_admin; no accountant/operator E2E. |
| **Login** | No E2E for tenant login or select-tenant; only platform login E2E. |
| **Frontend unit** | No tests for useRole/useAuth with different roles; no tests for role-based UI (Post/Settings visibility). |
| **Test users** | E2E seed creates accountant/operator but global-setup never uses them; platform_admin in seed may violate User model (tenant_id null). |
| **Use cases** | Invitation accept, payment reverse (with/without allocations), operator post forbidden, accountant settings forbidden – need explicit tests. |
| **Alignment** | Payment reverse API contract vs test expectation; Identity/TenantMembership vs legacy User in tests. |

---

## 8. Concrete Recommendations

### 8.1 Fix and Align

1. **ARStatementTest:** Resolve 201 vs 409 for payment reverse: update test to match API (e.g. 409 when payment has allocations) or fix API and add a test that payment without allocations can be reversed (201).
2. **E2E seed platform_admin:** Create platform_admin user with `tenant_id = null` in a dedicated step; remove platform_admin from the tenant-scoped loop in `ensureUsersPerRole` to avoid User model violation.
3. **PHPUnit metadata:** Replace doc-comment metadata with PHPUnit attributes where possible to avoid deprecation warnings.

### 8.2 Role-Based E2E

4. **Playwright projects per role:** Add storage state files for tenant_admin, accountant, operator (and optionally platform_admin). In global-setup (or a script), after seed, call auth-cookie for each role and save state. Add projects in `playwright.config.ts` that use these states.
5. **Specs for accountant:** Add a spec (e.g. `50_role_accountant.spec.ts`) that runs as accountant: dashboard, reports, post/reverse allowed; no Settings/Users/Modules; no close crop cycle.
6. **Specs for operator:** Add a spec (e.g. `51_role_operator.spec.ts`) that runs as operator: create transaction/work log; Post/Reverse not visible or disabled; API post returns 403.

### 8.3 Login and Invitation E2E

7. **Tenant login E2E:** Add a spec that uses a seeded tenant user, fills email/password on tenant login, submits, and asserts redirect to dashboard and correct tenant/role (no auth cookie injection for this flow).
8. **Select-tenant E2E:** If supported, multi-tenant user logs in, sees tenant list, selects tenant, and lands on dashboard with that tenant.
9. **Accept-invite E2E:** Open accept-invite URL (token from API or test helper), set password, submit, assert redirect to app and first login behavior if applicable.

### 8.4 Unit and Integration

10. **useRole / permissions:** Unit test `hasRole()` and `can()` for each role against expected capabilities (e.g. operator cannot post, accountant can post, tenant_admin can manage users).
11. **Role-based UI components:** For 2–3 key pages (e.g. SaleDetailPage, InvGrnDetailPage, DashboardPage), render with mock auth for tenant_admin, accountant, operator and assert visibility of Post, Reverse, Edit, Settings.
12. **Payment reverse API:** Dedicated feature test: payment with allocations → reverse returns 409; payment without allocations → reverse returns 201 (and AR statement excludes reversed payment).

### 8.5 Structure and Maintainability

13. **Shared role fixtures (API):** Introduce a trait or factory that creates tenant + users (tenant_admin, accountant, operator) and optionally platform_admin, and use it in RolePermissionEnforcementTest and new role tests.
14. **E2E helpers:** Add `loginAsRole(role)` helper (or per-project storage state) and document in README or `docs/E2E.md` how to run “as accountant” or “as operator”.
15. **Permission matrix test:** One test (frontend or API) that, for each role, asserts the set of allowed permissions or routes matches the intended matrix (e.g. from `permissions.ts` and route middleware).

---

## 9. Suggested Test Structure (High Level)

```
apps/api/tests/
  Feature/           # existing; add PaymentReverseGuardsTest, expand role tests
  Traits/
    CreatesRoleUsers.php   # optional: tenant + users per role

apps/web/
  src/
    components/__tests__/
      ProtectedRoute.test.tsx
      RoleGate.test.tsx     # new: component that shows/hides by role
    hooks/__tests__/
      useRole.test.ts
    config/__tests__/
      permissions.test.ts   # optional: role → permissions
  playwright/
    .auth/
      tenant_admin.json     # from global-setup or script
      accountant.json
      operator.json
    specs/
      50_role_accountant.spec.ts
      51_role_operator.spec.ts
      52_tenant_login.spec.ts
      53_accept_invite.spec.ts
    helpers/
      roleAuth.ts           # loginAsRole / getStateForRole
```

---

## 10. Priority Order

1. **P0 – Release block:** Fix ARStatementTest (align 201/409 and document behavior); fix E2E seed platform_admin (tenant_id null).
2. **P1 – High:** Role-based E2E (accountant + operator at least one run each); E2E tenant login; payment reverse API test (409 when allocations, 201 when none).
3. **P2 – Medium:** Unit tests for useRole and role-based UI; Playwright projects per role; invitation accept E2E; shared API role fixtures.
4. **P3 – Nice-to-have:** Permission matrix test; select-tenant E2E; PHPUnit attributes; more report visibility tests.

This should give you a clear path to production-grade coverage with focus on failing tests, role behavior, test user setup, and use case gaps.

---

## 11. Implementation Summary (Post-Audit)

**Date:** March 2025 (post-implementation)

### 11.1 Verified Findings

| Finding | Status | Notes |
|--------|--------|--------|
| ARStatementTest payment reverse expects 201, gets 409 | **Fixed** | Posting Payment IN auto-allocates to sales; test now unapplies then reverses. Added test for 409 when allocations exist. |
| E2ESeedService creates platform_admin with tenant_id | **Fixed** | platform_admin now created in `ensurePlatformAdminUser()` with `tenant_id` null. auth-cookie accepts null tenant_id for platform_admin. |
| E2E only runs as tenant_admin/platform_admin | **Fixed** | global-setup writes `accountant.json` and `operator.json`; specs 50_role_accountant and 51_role_operator use them. |
| No E2E tenant login | **Partially fixed** | 52_tenant_login.spec.ts added; submits seeded credentials. Full redirect to app depends on Identity being seeded (see blockers). |
| No unit tests for useRole / wrong-role | **Fixed** | useRole.test.ts added (all roles, hasRole, canPost, canManageUsers, can(permission)). ProtectedRoute test for operator added. |
| Payment reverse 409/201 contract | **Fixed** | ARStatementTest: unapply then reverse (201); new test_payment_reverse_returns_409_when_sales_allocations_exist. |
| Global-setup auth mechanism | **Valid** | Cookie + localStorage still used; global-setup updated to pass tenant_id null for platform_admin. |

### 11.2 Changelog (Implemented)

**P0**
- **ARStatementTest:** Unapply payment from sales before reverse so API returns 201; added `test_payment_reverse_returns_409_when_sales_allocations_exist` to assert API contract.
- **E2ESeedService:** `ensureUsersPerRole` limited to tenant_admin, accountant, operator; added `ensurePlatformAdminUser()` (tenant_id null). **DevE2ESeedController::authCookie:** tenant_id nullable; lookup by user_id + role + null tenant_id for platform_admin. **global-setup:** pass tenant_id null when role is platform_admin.

**P1**
- **Playwright:** global-setup writes `playwright/.auth/accountant.json` and `operator.json` after seed. **50_role_accountant.spec.ts:** accountant can open dashboard/reports, cannot see Settings, cannot access /app/settings/users. **51_role_operator.spec.ts:** operator can open dashboard/transactions, cannot see Settings, cannot access /app/settings/modules. **52_tenant_login.spec.ts:** login form present; submit with seeded credentials (redirect or error).

**P2**
- **useRole.test.ts:** 5 tests (unauthenticated, tenant_admin, accountant, operator, wrong-role). **ProtectedRoute.test.tsx:** added "renders children when authenticated as operator".

**P3**
- Permission matrix test, select-tenant E2E, accept-invite E2E, shared API role factory: not implemented (documented as remaining).

### 11.3 Tests Added or Changed

| Test / File | Change | Scenario |
|-------------|--------|----------|
| ARStatementTest::ar_statement_excludes_reversed_payment_in | Updated | Unapply payment then reverse; assert 201 and AR statement has one line. |
| ARStatementTest::test_payment_reverse_returns_409_when_sales_allocations_exist | Added | Post sale + payment (auto-allocated); reverse without unapply; assert 409 and message. |
| useRole.test.ts | Added | hasRole, canPost, canManageUsers, can(permission) for null, tenant_admin, accountant, operator; wrong-role. |
| ProtectedRoute.test.tsx | Updated | Renders children when authenticated as operator (auth-only gate). |
| 50_role_accountant.spec.ts | Added | Accountant: dashboard, reports, no Settings, blocked from /settings/users. |
| 51_role_operator.spec.ts | Added | Operator: dashboard, transactions, no Settings, blocked from /settings/modules. |
| 52_tenant_login.spec.ts | Updated | Deterministic: submit seeded credentials; assert redirect to /app/ (Identity+Membership seeded). |
| 53_select_tenant.spec.ts | Added | Multi-tenant user (e2e-multi@e2e.local) logs in, sees "Select a farm", chooses tenant, lands in app. |
| 54_accept_invite.spec.ts | Added | Open accept-invite with seeded token, fill name + password, assert redirect to app; skips if no token. |
| PermissionMatrixTest.php | Added | Table-driven role/route/status matrix (tenant_admin, accountant, operator, platform_admin). |
| CreatesTenantWithRoleUsers trait | Added | Shared tenant + role users and tenantRoleHeaders(); used by PermissionMatrixTest, RolePermissionEnforcementTest. |

### 11.4 Remaining Gaps / Blockers (updated after coverage close-out)

**Closed (implemented):**

- **Tenant login E2E:** E2E seed now creates Identity + TenantMembership + User for `e2e-tenant_admin@e2e.local` (and accountant, operator). 52_tenant_login.spec.ts asserts deterministic redirect to `/app/` after login.
- **Select-tenant E2E:** 53_select_tenant.spec.ts added. Seed creates `e2e-multi@e2e.local` with two memberships (E2E Farm, E2E Farm 2); E2E logs in, sees "Select a farm", chooses tenant, lands in app.
- **Accept-invite E2E:** 54_accept_invite.spec.ts added. Seed creates one invite for `e2e-invited@e2e.local` and returns `invite_token` in seed response (saved to `playwright/.auth/seed.json`); E2E opens `/accept-invite?token=...`, fills name + password, submits, asserts redirect to app. Skips if `invite_token` missing (e.g. after first accept).
- **Permission matrix test:** PermissionMatrixTest.php added (table-driven); covers tenant_admin, accountant, operator, platform_admin vs tenant/users, tenant/modules, platform/tenants, dashboard. RolePermissionEnforcementTest retained for detailed scenarios (GRN post/reverse, etc.).
- **Shared API role factory:** Trait `CreatesTenantWithRoleUsers` added in `tests/Traits/CreatesTenantWithRoleUsers.php`. Provides `tenantWithRoleUsers()` and `tenantRoleHeaders($role)`. Used by PermissionMatrixTest and RolePermissionEnforcementTest.

**Remaining / notes:**

- **Accept-invite token reuse:** After the first E2E run that executes accept-invite, the invite is consumed; subsequent seed runs return `invite_token: null` (user already exists). 54_accept_invite.spec.ts skips when token is missing. For repeated full E2E runs with accept-invite every time, a dev endpoint that creates a fresh invite and returns the token could be added (optional).
- **High-risk cases:** Operator post/reverse, accountant user/module restrictions, and platform isolation remain covered by RolePermissionEnforcementTest and PermissionMatrixTest; no new high-risk gaps identified.
