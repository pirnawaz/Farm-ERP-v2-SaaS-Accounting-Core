/**
 * Role-based E2E: accountant. Uses storage state from global-setup (accountant.json).
 * Accountant can: dashboard, reports, post/reverse, transactions.
 * Accountant cannot: Settings, Users, Modules (tenant admin only); close crop cycle.
 */

import { test, expect } from '@playwright/test';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('@all Role: accountant', () => {
  test.use({ storageState: 'playwright/.auth/accountant.json' });

  test('accountant can open dashboard', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByTestId('app-shell')).toBeVisible();
    await expect(page.getByTestId('app-sidebar')).toBeVisible();
  });

  test('accountant can open reports (trial balance)', async ({ page }) => {
    await page.goto('/app/reports/trial-balance', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Trial Balance|Report/i);
  });

  test('accountant does not see Settings in sidebar', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    const settingsLink = page.getByRole('link', { name: /Settings/i });
    await expect(settingsLink).toHaveCount(0);
  });

  test('accountant cannot access settings users (403 or redirect)', async ({ page }) => {
    await page.goto('/app/settings/users', { waitUntil: 'domcontentloaded' });
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

  test('accountant can open transactions list', async ({ page }) => {
    await page.goto('/app/transactions', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page).toHaveURL(/\/app\/transactions/);
  });
});
