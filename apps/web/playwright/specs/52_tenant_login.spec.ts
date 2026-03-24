/**
 * Tenant login flow (unified login). Uses no auth state.
 * E2E seed creates Identity + TenantMembership + User for e2e-tenant_admin@e2e.local
 * so login succeeds and we assert deterministic redirect to app.
 */

import { test, expect } from '@playwright/test';

test.describe('Tenant login', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('login page shows unified login form', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: /Welcome back|Terrava/i })).toBeVisible();
    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();
    await expect(page.getByTestId('login-submit')).toBeVisible();
  });

  test('tenant login with seeded credentials redirects to app', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[type="email"]').fill('e2e-tenant_admin@e2e.local');
    await page.locator('input[type="password"]').fill('password');
    await page.getByTestId('login-submit').click();

    await expect(page).toHaveURL(/\/app\//, { timeout: 10000 });
    await expect(page).not.toHaveURL(/\/login/);
  });
});
