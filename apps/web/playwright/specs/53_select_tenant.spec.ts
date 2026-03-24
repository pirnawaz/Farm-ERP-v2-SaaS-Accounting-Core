/**
 * Select-tenant flow: user with multiple tenant memberships sees tenant list after login,
 * chooses a tenant, and is redirected to the app. E2E seed creates e2e-multi@e2e.local
 * with two memberships (E2E Farm, E2E Farm 2).
 */

import { test, expect } from '@playwright/test';

test.describe('Select tenant', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('multi-tenant user sees tenant selection then lands in app', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[type="email"]').fill('e2e-multi@e2e.local');
    await page.locator('input[type="password"]').fill('password');
    await page.getByTestId('login-submit').click();

    await expect(page.getByRole('heading', { name: /Select a farm/i })).toBeVisible({ timeout: 10000 });
    await expect(page.getByText(/You have access to more than one farm/i)).toBeVisible();

    const farmSelect = page.locator('select').filter({ has: page.locator('option') });
    await expect(farmSelect).toBeVisible();
    const options = await farmSelect.locator('option').allTextContents();
    expect(options.length).toBeGreaterThanOrEqual(2);

    await farmSelect.selectOption({ index: 0 });
    await page.getByRole('button', { name: /Continue|Signing in/i }).click();

    await expect(page).toHaveURL(/\/app\//, { timeout: 10000 });
    await expect(page).not.toHaveURL(/\/login/);
  });
});
