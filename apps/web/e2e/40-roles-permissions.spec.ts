import { test, expect } from '@playwright/test';
import { loginDev } from './helpers/auth';
import { gotoTransactionDetail } from './helpers/nav';
import { gotoModuleManagement } from './helpers/nav';
import { gotoPlatformTenants } from './helpers/nav';
import { readSeedState } from './helpers/seed';

test.describe('40-roles-permissions', () => {
  test('Operator cannot see Post button on DRAFT record', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'operator', seed });
    await gotoTransactionDetail(page, seed.draft_transaction_id);
    await page.waitForURL(new RegExp(`/app/transactions/${seed.draft_transaction_id}`));
    await expect(page.locator('[data-testid=transaction-detail]')).toBeVisible({ timeout: 10_000 });
    const postBtn = page.locator('[data-testid=post-btn]').or(page.locator('button:has-text("Post")')).first();
    await expect(postBtn).toHaveCount(0);
  });

  test('Accountant can see Post button on DRAFT record', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'accountant', seed });
    await gotoTransactionDetail(page, seed.draft_transaction_id);
    await page.waitForURL(new RegExp(`/app/transactions/${seed.draft_transaction_id}`));
    await expect(page.locator('[data-testid=transaction-detail]')).toBeVisible({ timeout: 10_000 });
    const postBtn = page.locator('[data-testid=post-btn]').or(page.locator('button:has-text("Post")')).first();
    await expect(postBtn).toBeVisible();
  });

  test('Tenant admin can access module management', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'tenant_admin', seed });
    await gotoModuleManagement(page);
    await expect(page).toHaveURL(/\/app\/admin\/modules/);
  });

  test('Platform admin can access /app/platform/*', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'platform_admin', seed });
    await gotoPlatformTenants(page);
    await expect(page).toHaveURL(/\/app\/platform\/tenants/);
  });
});
