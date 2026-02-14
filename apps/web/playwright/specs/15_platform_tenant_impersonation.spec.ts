/**
 * Platform admin: tenant list, impersonation flow.
 * Requires platform_admin auth (e.g. E2E seed with all_modules or platform profile).
 */

import { test, expect } from '@playwright/test';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('Platform admin tenant list and impersonation', () => {
  test('platform admin can see tenant list', async ({ page }) => {
    await page.goto('/app/platform/tenants', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByRole('heading', { name: /tenants/i })).toBeVisible();
    await expect(page.getByTestId('app-shell')).toBeVisible();
  });

  test('initiate impersonation and see impersonation state indicator then exit', async ({
    page,
  }) => {
    await page.goto('/app/platform/tenants', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const impersonateBtn = page.getByTestId(/^impersonate-tenant-/).first();
    const count = await impersonateBtn.count();
    if (count === 0) {
      test.skip(true, 'No tenants to impersonate (seed may not have created any)');
      return;
    }

    await impersonateBtn.click();
    await page.waitForURL(/\/app\/dashboard/, { timeout: 10000 });

    await expect(page.getByTestId('impersonation-banner')).toBeVisible();
    await expect(page.getByTestId('impersonation-banner')).toContainText(/Impersonating/i);

    await page.getByTestId('impersonation-exit').click();
    await page.waitForURL(/\/app\/platform\/tenants/, { timeout: 5000 });

    await expect(page.getByTestId('impersonation-banner')).toHaveCount(0);
  });
});
