/**
 * @all When E2E_PROFILE=all: assert nav entries for optional modules exist and each module route loads.
 */

import { test, expect } from '@playwright/test';
import { getProfile } from '../helpers/profile';
import { assertNavVisible } from '../helpers/nav';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('@all All modules nav and routes', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  test('@all optional module nav entries exist', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForModulesReady(page);
    await expect(page.getByTestId('app-sidebar')).toBeVisible();
    await assertNavVisible(page, ['inventory', 'labour', 'ar_sales', 'settlements', 'machinery']);
  });

  test('@all each module main list page loads with stable heading or testid', async ({ page }) => {
    const routes: { path: string; headingOrTestId: string | RegExp }[] = [
      { path: '/app/land', headingOrTestId: /Land Parcels/i },
      { path: '/app/crop-cycles', headingOrTestId: /Crop Cycles/i },
      { path: '/app/allocations', headingOrTestId: /Allocations/i },
      { path: '/app/projects', headingOrTestId: /Projects/i },
      { path: '/app/transactions', headingOrTestId: /Transactions/i },
      { path: '/app/inventory', headingOrTestId: /Inventory/i },
      { path: '/app/labour', headingOrTestId: /Labour|Workers/i },
      { path: '/app/sales', headingOrTestId: /Sales/i },
      { path: '/app/settlement', headingOrTestId: /Settlement/i },
      { path: '/app/payments', headingOrTestId: /Payments/i },
      { path: '/app/advances', headingOrTestId: /Advances/i },
      { path: '/app/machinery/work-logs', headingOrTestId: /Work Logs|Machinery/i },
      { path: '/app/reports/trial-balance', headingOrTestId: 'report-heading-trial-balance' },
    ];
    for (const { path: routePath, headingOrTestId } of routes) {
      await page.goto(routePath);
      await waitForModulesReady(page);
      await expect(page).toHaveURL(new RegExp(routePath.replace(/\/$/, '')));
      if (typeof headingOrTestId === 'string') {
        await expect(page.getByTestId(headingOrTestId)).toBeVisible({ timeout: 10000 });
      } else {
        await expect(page.locator('body')).toContainText(headingOrTestId);
      }
    }
  });
});
