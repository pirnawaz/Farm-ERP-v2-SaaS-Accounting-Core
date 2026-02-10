import { test, expect } from '@playwright/test';
import { loginDev } from './helpers/auth';
import { gotoPlatformTenants } from './helpers/nav';
import { readSeedState } from './helpers/seed';

test.describe('10-platform-admin', () => {
  test('platform admin can access /app/platform/tenants', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'platform_admin', seed });
    await gotoPlatformTenants(page);
    await expect(page).toHaveURL(/\/app\/platform\/tenants/);
    await expect(page.locator('[data-testid=app-sidebar]').or(page.locator('aside')).or(page.locator('nav')).first()).toBeVisible();
  });
});
