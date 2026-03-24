/**
 * Accept-invite flow: open invite page with token (from E2E seed), fill name + password,
 * submit, then land in app. Seed creates one invite for e2e-invited@e2e.local and returns
 * invite_token in seed response (saved to playwright/.auth/seed.json by global-setup).
 */

import * as fs from 'fs';
import * as path from 'path';
import { test, expect } from '@playwright/test';

test.describe('Accept invite', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('accepting invite with seeded token redirects to app', async ({ page }) => {
    const seedPath = path.join(process.cwd(), 'playwright', '.auth', 'seed.json');
    if (!fs.existsSync(seedPath)) {
      test.skip();
      return;
    }
    const seed = JSON.parse(fs.readFileSync(seedPath, 'utf-8')) as { invite_token?: string };
    const token = seed.invite_token;
    if (!token) {
      test.skip();
      return;
    }

    await page.goto(`/accept-invite?token=${encodeURIComponent(token)}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: /Set up your account/i })).toBeVisible();
    await expect(page.locator('input#name')).toBeVisible();
    await expect(page.locator('input#password')).toBeVisible();

    await page.locator('input#name').fill('E2E Invited User');
    await page.locator('input#password').fill('password123');
    await page.getByRole('button', { name: /Activate account/i }).click();

    await expect(page).toHaveURL(/\/app\//, { timeout: 10000 });
    await expect(page).not.toHaveURL(/\/accept-invite/);
  });
});
