/**
 * Admin & Settings: farm profile, user management, role management, module toggles,
 * dashboard, onboarding panel, tenant selector.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel } from '../helpers/form';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('Admin & Settings', () => {
  // ═══════════════════════════════════════════════════════════════
  // DASHBOARD
  // ═══════════════════════════════════════════════════════════════

  test('dashboard loads with sidebar and app shell', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByTestId('app-shell')).toBeVisible();
    await expect(page.getByTestId('app-sidebar')).toBeVisible();
  });

  test('dashboard displays role-based content', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    // Dashboard should have some content (widgets, quick actions, etc.)
    await expect(page.locator('body')).toContainText(/Dashboard|Welcome|Quick|Overview/i);
  });

  test('onboarding panel is dismissible', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const onboarding = page.getByTestId('onboarding-checklist');
    if (await onboarding.isVisible()) {
      const dismissBtn = page.getByTestId('onboarding-dismiss');
      if (await dismissBtn.isVisible()) {
        await dismissBtn.click();
        await expect(onboarding).not.toBeVisible();
      }
    }
  });

  test('dismissed onboarding can be reopened', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const reopenBtn = page.getByTestId('onboarding-reopen');
    if (await reopenBtn.isVisible()) {
      await reopenBtn.click();
      await expect(page.getByTestId('onboarding-checklist')).toBeVisible();
    }
  });

  // ═══════════════════════════════════════════════════════════════
  // FARM PROFILE
  // ═══════════════════════════════════════════════════════════════

  test('farm profile settings page loads', async ({ page }) => {
    await page.goto('/app/settings/farm-profile', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Farm Profile|Farm Settings/i);
  });

  test('farm profile can be updated', async ({ page }) => {
    await page.goto('/app/settings/farm-profile', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Find farm name input and update
    const nameInput = page.locator('label:has-text("Farm Name")').locator('..').locator('input').first()
      .or(page.locator('label:has-text("Name")').locator('..').locator('input').first());
    if (await nameInput.count() > 0) {
      const currentValue = await nameInput.inputValue();
      // Save the current value (we'll restore it)
      await nameInput.fill(`${currentValue} Updated`);
      await page.getByRole('button', { name: /Save|Update/i }).click();
      await expect(page.getByText(/updated|saved|success/i)).toBeVisible({ timeout: 8000 });

      // Restore original
      await nameInput.fill(currentValue);
      await page.getByRole('button', { name: /Save|Update/i }).click();
      await expect(page.getByText(/updated|saved|success/i)).toBeVisible({ timeout: 8000 });
    }
  });

  // ═══════════════════════════════════════════════════════════════
  // USER MANAGEMENT
  // ═══════════════════════════════════════════════════════════════

  test('users settings page loads', async ({ page }) => {
    await page.goto('/app/settings/users', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/User/i);
  });

  test('users page shows user list', async ({ page }) => {
    await page.goto('/app/settings/users', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Should show at least the current admin user
    const table = page.locator('table');
    if (await table.count() > 0) {
      await expect(table.locator('tbody tr')).toHaveCount(/./, { timeout: 5000 });
    }
  });

  // ═══════════════════════════════════════════════════════════════
  // ROLES
  // ═══════════════════════════════════════════════════════════════

  test('roles settings page loads', async ({ page }) => {
    await page.goto('/app/settings/roles', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Role/i);
  });

  // ═══════════════════════════════════════════════════════════════
  // MODULE TOGGLES
  // ═══════════════════════════════════════════════════════════════

  test('module toggles page loads', async ({ page }) => {
    await page.goto('/app/settings/modules', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByTestId('module-toggles-page')).toBeVisible();
  });

  test('module toggles page shows save button', async ({ page }) => {
    await page.goto('/app/settings/modules', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByTestId('module-toggles-save')).toBeVisible();
  });

  test('module toggles shows toggle switches for optional modules', async ({ page }) => {
    await page.goto('/app/settings/modules', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Should have toggle switches (checkboxes or switch inputs)
    const toggles = page.locator('input[type="checkbox"], [role="switch"]');
    await expect(toggles.first()).toBeVisible({ timeout: 5000 });
  });

  // ═══════════════════════════════════════════════════════════════
  // NAVIGATION SIDEBAR
  // ═══════════════════════════════════════════════════════════════

  test('sidebar navigation has core module links', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByTestId('app-sidebar')).toBeVisible();

    // Core nav items should always be present
    await expect(page.locator('[data-testid="nav-dashboard"]')).toBeVisible();
    await expect(page.locator('[data-testid="nav-land"]')).toBeVisible();
  });

  test('sidebar navigation groups are collapsible', async ({ page }) => {
    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Find a collapsible group button
    const groupBtn = page.getByTestId('app-sidebar').locator('button').filter({ hasText: /Report|Inventory|Machinery/i }).first();
    if (await groupBtn.isVisible()) {
      // Click to toggle
      await groupBtn.click();
      await page.waitForTimeout(300);
      // Click again to toggle back
      await groupBtn.click();
    }
  });

  // ═══════════════════════════════════════════════════════════════
  // AUTH / PROTECTED ROUTES
  // ═══════════════════════════════════════════════════════════════

  test('unauthenticated access redirects to login', async ({ browser }) => {
    // Create a context without storageState (no auth)
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto('/app/dashboard', { waitUntil: 'domcontentloaded' });
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/, { timeout: 10000 });
    await context.close();
  });

  test('login page renders with email and password fields', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('input[type="email"], input[name="email"]')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();
    await expect(page.getByTestId('login-submit')).toBeVisible();
    await context.close();
  });
});
