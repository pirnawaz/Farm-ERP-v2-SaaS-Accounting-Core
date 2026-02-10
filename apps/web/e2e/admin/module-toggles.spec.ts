/**
 * Module Toggles UI smoke test (the only E2E that drives the toggles screen).
 * All other module state is set via API/db in beforeAll/beforeEach.
 */
import { test, expect } from '@playwright/test';
import { loginDev } from '../helpers/auth';
import { gotoModuleManagement } from '../helpers/nav';
import { readSeedState } from '../helpers/seed';

test.describe('Admin Module Toggles UI', () => {
  test('tenant admin can open module toggles; all core modules show Core badge and toggle disabled', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'tenant_admin', seed });
    await gotoModuleManagement(page);
    await expect(page).toHaveURL(/\/app\/admin\/modules/);
    await expect(page.getByTestId('module-toggles-page')).toBeVisible();

    const coreKeys = ['accounting_core', 'projects_crop_cycles', 'reports', 'treasury_payments'];
    for (const key of coreKeys) {
      const coreRow = page.getByTestId(`module-row-${key}`);
      await expect(coreRow).toBeVisible();
      await expect(coreRow.getByTestId('module-badge-core')).toBeVisible();
      const coreToggle = page.getByTestId(`module-toggle-${key}`);
      await expect(coreToggle).toBeDisabled();
    }
  });

  test('enable/disable non-core module (land), Save, success toast, reload confirms persisted', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'tenant_admin', seed });
    await gotoModuleManagement(page);
    await expect(page.getByTestId('module-toggles-page')).toBeVisible();

    const landToggle = page.getByTestId('module-toggle-land');
    const landRow = page.getByTestId('module-row-land');
    await expect(landRow).toBeVisible();

    const wasOn = (await landToggle.getAttribute('aria-checked')) === 'true';
    await landToggle.click();
    await page.getByTestId('module-toggles-save').click();
    await expect(page.getByText('Module settings saved')).toBeVisible({ timeout: 5000 });

    await page.reload();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.getByTestId('module-toggles-page')).toBeVisible({ timeout: 10_000 });
    const landToggleAfter = page.getByTestId('module-toggle-land');
    const nowOn = (await landToggleAfter.getAttribute('aria-checked')) === 'true';
    expect(nowOn).toBe(!wasOn);

    if (nowOn !== wasOn) {
      landToggleAfter.click();
      await page.getByTestId('module-toggles-save').click();
      await expect(page.getByText('Module settings saved')).toBeVisible({ timeout: 5000 });
    }
  });
});
