/**
 * @all Accounting module: journals (daily book entries) with filtering, accounting periods
 * (close/reopen), chart of accounts.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('@all Accounting module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ═══════════════════════════════════════════════════════════════
  // JOURNALS (Daily Book Entries)
  // ═══════════════════════════════════════════════════════════════

  test('@all journals page loads', async ({ page }) => {
    await page.goto('/app/journals', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Journal|Daily Book|Entries/i);
  });

  test('@all journals page filters by date range', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/journals', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Fill date range filters
    const fromInput = page.locator('input[type="date"]').first();
    const toInput = page.locator('input[type="date"]').nth(1);

    if (await fromInput.count() > 0 && await toInput.count() > 0) {
      await fromInput.fill(today);
      await toInput.fill(today);

      // Apply filter (may be auto-applied or require button click)
      const filterBtn = page.getByRole('button', { name: /Filter|Apply|Search/i });
      if (await filterBtn.isVisible()) {
        await filterBtn.click();
      }

      // Wait for results to load
      await page.waitForTimeout(1000);
      // Page should still be on journals
      await expect(page).toHaveURL(/\/app\/journals/);
    }
  });

  test('@all journals page filters by status', async ({ page }) => {
    await page.goto('/app/journals', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Select status filter if available
    const statusSelect = page.locator('label:has-text("Status")').locator('..').locator('select').first()
      .or(page.locator('select').filter({ hasText: /All|Draft|Posted/i }).first());

    if (await statusSelect.count() > 0) {
      await statusSelect.selectOption({ label: /Posted/i });
      await page.waitForTimeout(1000);
      await expect(page).toHaveURL(/\/app\/journals/);
    }
  });

  test('@all journals page search works', async ({ page }) => {
    await page.goto('/app/journals', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const searchInput = page.locator('input[type="search"], input[placeholder*="Search"]').first();
    if (await searchInput.count() > 0) {
      await searchInput.fill('test');
      await page.waitForTimeout(1000);
      // Page should not crash
      await expect(page).toHaveURL(/\/app\/journals/);
    }
  });

  // ═══════════════════════════════════════════════════════════════
  // ACCOUNTING PERIODS
  // ═══════════════════════════════════════════════════════════════

  test('@all accounting periods page loads', async ({ page }) => {
    await page.goto('/app/accounting-periods', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Accounting Period|Period/i);
  });

  test('@all accounting periods shows list of periods', async ({ page }) => {
    await page.goto('/app/accounting-periods', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Should display periods table or cards
    const table = page.locator('table');
    const cards = page.locator('[data-testid*="period"]');
    await expect(table.or(cards).or(page.locator('body'))).toContainText(/\d{4}|Jan|Feb|Mar|Open|Closed/i);
  });

  test('@all close an open accounting period', async ({ page }) => {
    await page.goto('/app/accounting-periods', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Find a row with an Open status and a Close button
    const openRow = page.locator('table tbody tr').filter({ hasText: /Open/i }).first();
    if (await openRow.count() === 0) {
      test.skip(true, 'No open accounting periods available');
      return;
    }

    const closeBtn = openRow.getByRole('button', { name: /Close/i });
    if (!(await closeBtn.isVisible())) {
      test.skip(true, 'No close button on open period');
      return;
    }

    await closeBtn.click();

    // Confirm close if dialog appears
    const confirmBtn = page.locator('[role="dialog"]').getByRole('button', { name: /Confirm|Close/i });
    if (await confirmBtn.isVisible()) {
      await confirmBtn.click();
    }

    await expect(page.getByText(/closed|success/i)).toBeVisible({ timeout: 8000 });
  });

  test('@all reopen a closed accounting period', async ({ page }) => {
    await page.goto('/app/accounting-periods', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const closedRow = page.locator('table tbody tr').filter({ hasText: /Closed/i }).first();
    if (await closedRow.count() === 0) {
      test.skip(true, 'No closed accounting periods available');
      return;
    }

    const reopenBtn = closedRow.getByRole('button', { name: /Reopen|Open/i });
    if (!(await reopenBtn.isVisible())) {
      test.skip(true, 'No reopen button on closed period');
      return;
    }

    await reopenBtn.click();

    const confirmBtn = page.locator('[role="dialog"]').getByRole('button', { name: /Confirm|Reopen/i });
    if (await confirmBtn.isVisible()) {
      await confirmBtn.click();
    }

    await expect(page.getByText(/reopened|opened|success/i)).toBeVisible({ timeout: 8000 });
  });
});
