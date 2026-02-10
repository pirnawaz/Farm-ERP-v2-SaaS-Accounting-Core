import { test, expect } from '@playwright/test';
import { loginDev } from './helpers/auth';
import { gotoModuleManagement } from './helpers/nav';
import { readSeedState } from './helpers/seed';

test.describe('20-tenant-admin', () => {
  test('tenant admin can access module management', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'tenant_admin', seed });
    await gotoModuleManagement(page);
    await expect(page).toHaveURL(/\/app\/admin\/modules/);
    await expect(page.locator('[data-testid=app-sidebar]').or(page.locator('aside')).or(page.locator('nav')).first()).toBeVisible();
  });
});
