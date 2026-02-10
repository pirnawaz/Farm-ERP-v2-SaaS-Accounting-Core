import { Page } from '@playwright/test';
import { setAuthContext, addAuthContextInitScript } from './headers';
import { expectAppShellVisible } from './asserts';
import { gotoLogin } from './nav';
import { getDefaultTenantId } from './data';
import type { UserRole } from './data';
import type { SeedState } from './seed';

export interface LoginOptions {
  email?: string;
  password?: string;
  tenantId?: string;
  role: UserRole;
  userId?: string;
}

export interface LoginDevOptions {
  tenantId: string;
  role: UserRole;
  /** Seed state with user IDs; required so auth cookie can be set. */
  seed: SeedState;
}

const ROLE_USER_ID_KEYS: Record<UserRole, keyof SeedState> = {
  tenant_admin: 'tenant_admin_user_id',
  accountant: 'accountant_user_id',
  operator: 'operator_user_id',
  platform_admin: 'platform_admin_user_id',
};

/** Role-appropriate stable route after login (so shell loads on a real page). */
const ROLE_LANDING_ROUTES: Record<UserRole, string> = {
  platform_admin: '/app/platform/tenants',
  tenant_admin: '/app/admin/modules',
  accountant: '/app/transactions',
  operator: '/app/transactions',
};

/**
 * Set API auth cookie via dev-only endpoint; inject for BOTH BASE_URL and API_URL origins
 * so transaction pages work whether the frontend uses Vite proxy (/api on :3000) or direct API (:8000).
 */
async function setAuthCookieViaApi(
  page: Page,
  apiUrl: string,
  baseUrl: string,
  tenantId: string,
  role: UserRole,
  userId: string
): Promise<void> {
  const res = await fetch(`${apiUrl}/api/dev/e2e/auth-cookie`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ tenant_id: tenantId, role, user_id: userId }),
  });
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`E2E auth-cookie failed (${res.status}): ${text}. Ensure API has APP_DEBUG=true and seed was run.`);
  }
  const setCookie = res.headers.get('set-cookie');
  if (!setCookie) {
    throw new Error('E2E auth-cookie: no Set-Cookie in response.');
  }
  const match = /farm_erp_auth_token=([^;]+)/.exec(setCookie);
  if (!match) {
    throw new Error('E2E auth-cookie: could not parse farm_erp_auth_token from Set-Cookie.');
  }
  const value = match[1].trim();
  const apiOrigin = apiUrl.replace(/\/$/, '');
  const baseOrigin = baseUrl.replace(/\/$/, '');
  await page.context().addCookies([
    { name: 'farm_erp_auth_token', value, url: baseOrigin },
    { name: 'farm_erp_auth_token', value, url: apiOrigin },
  ]);
}

/**
 * DEV-mode auth bypass: set tenant/role/user in localStorage before any load (addInitScript),
 * set auth cookie for both BASE_URL and API_URL origins, then go straight to role landing route.
 * Works whether the frontend calls API via Vite proxy (/api on :3000) or direct API_URL (:8000).
 */
export async function loginDev(page: Page, options: LoginDevOptions): Promise<void> {
  const userId = options.seed[ROLE_USER_ID_KEYS[options.role]] as string | undefined;
  if (!userId) {
    throw new Error(
      `E2E seed state missing user ID for role "${options.role}" (e.g. accountant_user_id). Re-run seed: API with APP_DEBUG=true, then run E2E so globalSetup calls POST /api/dev/e2e/seed.`
    );
  }

  const tenantIdForStorage = options.role === 'platform_admin' ? '' : options.tenantId;
  await addAuthContextInitScript(page, {
    tenantId: tenantIdForStorage,
    userRole: options.role,
    userId,
  });

  const apiUrl = process.env.API_URL || 'http://localhost:8000';
  const baseUrl = process.env.BASE_URL || 'http://localhost:3000';
  await setAuthCookieViaApi(page, apiUrl, baseUrl, options.tenantId, options.role, userId);

  const landingRoute = ROLE_LANDING_ROUTES[options.role];
  await page.goto(landingRoute);
  await expectAppShellVisible(page);
}

/**
 * NOTE: loginViaUI is intentionally not used in E2E yet; use loginDev() until auth UI is stable.
 *
 * Log in via the UI: go to /login, select tenant + role (or email/password if present), submit.
 * Then set localStorage to match app keys and assert app shell is visible.
 */
export async function loginViaUI(page: Page, options: LoginOptions): Promise<void> {
  const { role, tenantId = getDefaultTenantId(), userId } = options;
  await gotoLogin(page);

  // Optional email/password (future or alternate auth)
  const emailSel = page.locator('[data-testid=email], input[type=email], input[name=email]').first();
  const passwordSel = page.locator('[data-testid=password], input[type=password], input[name=password]').first();
  if (await emailSel.isVisible().catch(() => false) && options.email) {
    await emailSel.fill(options.email);
  }
  if (await passwordSel.isVisible().catch(() => false) && options.password) {
    await passwordSel.fill(options.password);
  }

  // Dev flow: select tenant (click "Select" on a row) only when not platform_admin
  if (role !== 'platform_admin') {
    const rowWithSelect = page.locator('table tbody tr:has(button:has-text("Select"))').first();
    const selectBtn = rowWithSelect.locator('button:has-text("Select")').first();
    if (await selectBtn.isVisible().catch(() => false)) {
      await selectBtn.click();
    }
  }

  // Role: data-testid=role or #role
  const roleSelect = page.locator('[data-testid=role], #role').first();
  await roleSelect.selectOption(role);

  // Submit: data-testid=login-submit or button with Continue / submit
  const submitBtn = page.locator('[data-testid=login-submit], button[type=submit], button:has-text("Continue")').first();
  await submitBtn.click();

  // Wait for navigation (to /app/dashboard or /app/platform/tenants)
  await page.waitForURL(/\/(app\/dashboard|app\/platform)/, { timeout: 15_000 }).catch(() => {});

  // Set localStorage so api-client sends X-Tenant-Id, X-User-Role, X-User-Id (platform_admin has no tenant)
  const finalTenantId = role === 'platform_admin' ? '' : tenantId;
  await setAuthContext(page, {
    tenantId: finalTenantId,
    userRole: role,
    userId,
  });

  // Reload so app sees the keys (if we set after navigation)
  if (role !== 'platform_admin' && tenantId) {
    await page.reload();
    await page.waitForLoadState('networkidle').catch(() => {});
  }

  await expectAppShellVisible(page);
}
