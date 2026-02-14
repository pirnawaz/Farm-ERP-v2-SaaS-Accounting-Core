import { test, expect } from '@playwright/test';

test('dashboard loads when authenticated', async ({ page }) => {
  await page.goto('/app/dashboard');
  await expect(page).toHaveURL(/\/app\/dashboard/);
  await expect(page.locator('body')).toContainText(/Dashboard/i);
});
