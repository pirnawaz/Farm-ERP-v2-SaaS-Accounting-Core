import { test, expect } from '@playwright/test';
import { waitForModulesReady } from '../helpers/readiness';

test('@core dashboard loads when authenticated', async ({ page }) => {
  await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
  await waitForModulesReady(page);
  await expect(page).toHaveURL(/\/app\/dashboard/);
  await expect(page.locator('body')).toContainText(/Dashboard/i);
});
