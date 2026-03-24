/**
 * Role-based E2E: operator. Uses storage state from global-setup (operator.json).
 * Operator can: create/edit operational data (transactions, work logs where enabled).
 * Operator cannot: post/reverse; Settings, Users, Modules; close crop cycle.
 */

import { test, expect } from '@playwright/test';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('@all Role: operator', () => {
  test.use({ storageState: 'playwright/.auth/operator.json' });

  test('operator can open dashboard', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByTestId('app-shell')).toBeVisible();
  });

  test('operator does not see Settings in sidebar', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    const settingsLink = page.getByRole('link', { name: /Settings/i });
    await expect(settingsLink).toHaveCount(0);
  });

  test('operator can open transactions list', async ({ page }) => {
    await page.goto('/app/transactions', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page).toHaveURL(/\/app\/transactions/);
  });

  test('operator cannot access settings modules (403 or redirect)', async ({ page }) => {
    await page.goto('/app/settings/modules', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    const url = page.url();
    const body = await page.locator('body').textContent();
    const blocked =
      url.includes('/login') ||
      (body?.includes('403') ?? false) ||
      (body?.toLowerCase().includes('forbidden') ?? false) ||
      (body?.toLowerCase().includes('permission') ?? false);
    expect(blocked).toBeTruthy();
  });
});
