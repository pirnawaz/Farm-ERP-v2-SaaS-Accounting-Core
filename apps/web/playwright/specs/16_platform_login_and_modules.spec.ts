/**
 * Platform admin: login via platform auth (no tenant), navigate to tenants; tenant detail modules UI.
 * - Platform login: use login page in platform mode, then /app/platform/tenants.
 * - Tenant modules: toggle module, reload, assert persisted; plan change reflects disallowed modules.
 * - Module gating: inventory API 403 → 200 → 403 when toggled via platform PUT.
 */

import { test, expect } from '@playwright/test';
import { waitForModulesReady } from '../helpers/readiness';
import { getProfile } from '../helpers/profile';
import { getSeed } from '../helpers/seed';
import { apiUrl } from '../helpers/api';

const PLATFORM_ADMIN_EMAIL = 'e2e-platform_admin@e2e.local';
const PLATFORM_ADMIN_PASSWORD = 'password';

test.describe('Platform admin login and modules', () => {
  test.describe('Platform login (no auth state)', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('platform admin login then navigate to /app/platform/tenants', async ({ page }) => {
      await page.goto('/login', { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: /platform admin login/i }).click();
      await page.getByLabel(/email/i).fill(PLATFORM_ADMIN_EMAIL);
      await page.getByLabel(/password/i).fill(PLATFORM_ADMIN_PASSWORD);
      await page.getByTestId('platform-login-submit').click();
      await page.waitForURL(/\/app\/platform\/tenants/, { timeout: 10000 });
      await waitForModulesReady(page);
      await expect(page.getByRole('heading', { name: /tenants/i })).toBeVisible();
    });
  });

  test('tenant detail: modules section shows list and plan', async ({ page }) => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Requires E2E_PROFILE=all (platform admin auth)');
    await page.goto('/app/platform/tenants', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    const viewLink = page.getByRole('link', { name: 'View' }).first();
    const count = await viewLink.count();
    if (count === 0) {
      test.skip(true, 'No tenants in list (seed may not have run with platform profile)');
      return;
    }
    await viewLink.click();
    await page.waitForURL(/\/app\/platform\/tenants\/[a-f0-9-]+/, { timeout: 5000 });
    await expect(page.getByText(/modules/i)).toBeVisible();
    await expect(page.getByText(/plan/i)).toBeVisible();
  });

  test('tenant detail: toggle module off then on, reload shows persisted state', async ({
    page,
  }) => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Requires E2E_PROFILE=all (platform admin auth)');
    await page.goto('/app/platform/tenants', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    const viewLink = page.getByRole('link', { name: 'View' }).first();
    if ((await viewLink.count()) === 0) {
      test.skip(true, 'No tenants');
      return;
    }
    await viewLink.click();
    await page.waitForURL(/\/app\/platform\/tenants\/[a-f0-9-]+/, { timeout: 5000 });

    const moduleCheckboxes = page.getByRole('checkbox').filter({ hasNot: page.getByTitle(/core/) });
    const firstOptional = moduleCheckboxes.first();
    const count = await firstOptional.count();
    if (count === 0) {
      test.skip(true, 'No optional module toggles (all may be core or plan-restricted)');
      return;
    }
    const wasChecked = await firstOptional.isChecked();
    await firstOptional.click();
    await expect(firstOptional).toBeChecked({ checked: !wasChecked });
    await firstOptional.click();
    await expect(firstOptional).toBeChecked({ checked: wasChecked });

    await page.reload({ waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    const afterReload = page.getByRole('checkbox').filter({ hasNot: page.getByTitle(/core/) }).first();
    await expect(afterReload).toBeChecked({ checked: wasChecked });
  });

  test('tenant detail: set plan to Starter, module toggles reflect disallowed', async ({
    page,
  }) => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Requires E2E_PROFILE=all (platform admin auth)');
    await page.goto('/app/platform/tenants', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    const viewLink = page.getByRole('link', { name: 'View' }).first();
    if ((await viewLink.count()) === 0) {
      test.skip(true, 'No tenants');
      return;
    }
    await viewLink.click();
    await page.waitForURL(/\/app\/platform\/tenants\/[a-f0-9-]+/, { timeout: 5000 });

    await page.getByRole('button', { name: /edit tenant/i }).click();
    await page.getByLabel(/plan/i).selectOption('starter');
    await page.getByRole('button', { name: /save/i }).click();
    await expect(page.getByText(/starter/i).first()).toBeVisible({ timeout: 5000 });

    const notOnPlan = page.getByText(/not on plan/i);
    await expect(notOnPlan.first()).toBeVisible({ timeout: 3000 });
  });

  test('module gating: inventory API returns 403 → 200 → 403 when toggled', async ({
    request,
  }) => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Requires E2E_PROFILE=all (platform admin auth)');

    const seed = getSeed() as {
      tenant_id: string;
      tenant_admin_user_id: string;
      platform_admin_user_id: string;
    };
    const { tenant_id: tenantId, tenant_admin_user_id: tenantAdminUserId, platform_admin_user_id: platformAdminUserId } = seed;

    const platformHeaders = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-User-Id': platformAdminUserId,
      'X-User-Role': 'platform_admin',
    };
    const tenantHeaders = {
      Accept: 'application/json',
      'X-Tenant-Id': tenantId,
      'X-User-Id': tenantAdminUserId,
      'X-User-Role': 'tenant_admin',
    };

    const modulesUrl = apiUrl(`/api/platform/tenants/${tenantId}/modules`);
    const inventoryItemsUrl = apiUrl('/api/v1/inventory/items');

    const putModules = async (enabled: boolean) => {
      const res = await request.put(modulesUrl, {
        headers: platformHeaders,
        data: { modules: [{ key: 'inventory', enabled }] },
      });
      return res;
    };

    const getInventoryItems = async () =>
      request.get(inventoryItemsUrl, { headers: tenantHeaders });

    // Ensure tenant can enable inventory (plan allows it)
    const tenantUrl = apiUrl(`/api/platform/tenants/${tenantId}`);
    const patchRes = await request.patch(tenantUrl, {
      headers: platformHeaders,
      data: { plan_key: 'growth' },
    });
    if (!patchRes.ok() && patchRes.status() !== 404) {
      // Ignore if PATCH not supported or tenant not found
    }

    // 1) Disable inventory
    const putDisable1 = await putModules(false);
    expect(putDisable1.status()).toBe(200);

    // 2) GET inventory/items → 403
    const get1 = await getInventoryItems();
    expect(get1.status()).toBe(403)

    // 3) Enable inventory
    const putEnable = await putModules(true);
    expect(putEnable.status()).toBe(200);

    // 4) GET inventory/items → 200, valid JSON
    const get2 = await getInventoryItems();
    expect(get2.status()).toBe(200);
    const body2 = await get2.text();
    expect(() => JSON.parse(body2)).not.toThrow();
    const parsed = JSON.parse(body2);
    expect(Array.isArray(parsed) || (typeof parsed === 'object' && parsed !== null)).toBe(true);

    // 5) Disable again
    const putDisable2 = await putModules(false);
    expect(putDisable2.status()).toBe(200);

    // 6) GET inventory/items → 403 again
    const get3 = await getInventoryItems();
    expect(get3.status()).toBe(403);
  });

  test('platform audit logs page loads with table and filters', async ({ page }) => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Requires E2E_PROFILE=all (platform admin auth)');
    await page.goto('/app/platform/audit-logs', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByRole('heading', { name: /audit logs/i })).toBeVisible();
    await expect(page.getByText(/filters/i)).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();
  });
});
