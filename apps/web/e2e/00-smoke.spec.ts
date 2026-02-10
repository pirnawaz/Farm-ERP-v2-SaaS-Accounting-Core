import { test, expect } from '@playwright/test';
import { loginDev } from './helpers/auth';
import { gotoLogin } from './helpers/nav';
import { readSeedState } from './helpers/seed';

test.describe('00-smoke', () => {
  test('login page loads', async ({ page }) => {
    await gotoLogin(page);
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('text=Welcome back').or(page.locator('text=Terrava')).first()).toBeVisible();
  });

  test('login as operator and see app shell', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'operator', seed });
    await expect(page).toHaveURL(/\/app\//);
    await expect(page.locator('[data-testid=app-sidebar]').or(page.locator('aside')).or(page.locator('nav')).first()).toBeVisible();
  });
});
