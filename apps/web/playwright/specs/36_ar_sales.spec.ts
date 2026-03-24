/**
 * @all AR/Sales module: sales CRUD with line items, post/reverse lifecycle,
 * apply/unapply payment allocations, AR statement, open invoices.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `SAL-${Date.now()}`;

test.describe('@all AR / Sales module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ─── Sales List ──────────────────────────────────────────────
  test('@all sales list page loads', async ({ page }) => {
    await page.goto('/app/sales', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Sales/i);
  });

  // ─── Create Sale ─────────────────────────────────────────────
  test('@all create a sale with line items', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/sales/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Select buyer party
    const buyerSelect = page.locator('label:has-text("Buyer")').locator('..').locator('select').first();
    if (await buyerSelect.count() > 0) {
      await buyerSelect.selectOption({ index: 1 });
    }

    await fillByLabel(page, 'Date', today);
    await fillByLabel(page, 'Reference', `SALE-${runId}`);

    // Add line items if UI supports it
    const addLineBtn = page.getByRole('button', { name: /Add Line|Add Item/i });
    if (await addLineBtn.isVisible()) {
      await addLineBtn.click();
      const lineRow = page.locator('table tbody tr, [data-testid*="line"]').last();
      // Fill description
      const descInput = lineRow.locator('input').first();
      if (await descInput.count() > 0) {
        await descInput.fill(`Sale item ${runId}`);
      }
      // Fill quantity
      const qtyInput = lineRow.locator('input[type="number"]').first();
      if (await qtyInput.count() > 0) {
        await qtyInput.fill('10');
      }
      // Fill unit price
      const priceInput = lineRow.locator('input[type="number"]').nth(1);
      if (await priceInput.count() > 0) {
        await priceInput.fill('50');
      }
    } else {
      // Flat amount field
      await fillByLabel(page, 'Amount', '500');
    }

    await page.getByRole('button', { name: /Save|Create/i }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Post Sale ───────────────────────────────────────────────
  test('@all post sale and verify posting group', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/sales', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      test.skip(true, 'No draft sales to post');
      return;
    }
    await draftRow.click();
    await expect(page).toHaveURL(/\/app\/sales\/[0-9a-f-]+/);

    await page.getByTestId('post-btn').click();
    await page.getByTestId('posting-date-input').fill(today);
    await page.getByTestId('confirm-post').click();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('POSTED', {
      timeout: 10000,
    });
    await expect(
      page.getByTestId('posting-group-id').or(page.getByTestId('posting-group-panel'))
    ).toBeVisible();
  });

  // ─── Reverse Sale ────────────────────────────────────────────
  test('@all reverse posted sale', async ({ page }) => {
    await page.goto('/app/sales', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted sales to reverse');
      return;
    }
    await postedRow.click();

    const reverseBtn = page.getByRole('button', { name: /Reverse/i });
    if (await reverseBtn.isVisible()) {
      await reverseBtn.click();
      const confirmBtn = page.locator('[role="dialog"]').getByRole('button', { name: /Confirm|Reverse/i });
      if (await confirmBtn.isVisible()) {
        await confirmBtn.click();
      }
      await expect(page.locator('[data-testid="status-badge"]')).toContainText(/VOID|REVERSED/i, {
        timeout: 10000,
      });
    }
  });

  // ─── Apply Payment to Sale ───────────────────────────────────
  test('@all apply payment to posted sale', async ({ page }) => {
    await page.goto('/app/sales', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted sales for apply-payment test');
      return;
    }
    await postedRow.click();

    const applyBtn = page.getByRole('button', { name: /Apply Payment|Apply/i });
    if (!(await applyBtn.isVisible())) {
      test.skip(true, 'Apply payment button not visible on this sale');
      return;
    }
    await applyBtn.click();

    // Should show allocation dialog with available payments
    await expect(page.locator('[role="dialog"]')).toBeVisible({ timeout: 5000 });
    const paymentRow = page.locator('[role="dialog"]').locator('table tbody tr, [data-testid*="allocation"]').first();
    if (await paymentRow.count() === 0) {
      // No payments available to apply
      await page.locator('[role="dialog"]').getByRole('button', { name: /Cancel|Close/i }).click();
      return;
    }

    // Select payment and confirm
    const checkbox = paymentRow.locator('input[type="checkbox"]').first();
    if (await checkbox.count() > 0) {
      await checkbox.check();
    }
    await page.locator('[role="dialog"]').getByRole('button', { name: /Apply|Confirm|Save/i }).click();
    await expect(page.getByText(/applied|allocation|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Unapply Payment ────────────────────────────────────────
  test('@all unapply payment from sale', async ({ page }) => {
    await page.goto('/app/sales', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted sales for unapply test');
      return;
    }
    await postedRow.click();

    const unapplyBtn = page.getByRole('button', { name: /Unapply|Remove Allocation/i });
    if (!(await unapplyBtn.isVisible())) {
      test.skip(true, 'No unapply button visible (no allocations to remove)');
      return;
    }
    await unapplyBtn.click();

    const confirmBtn = page.locator('[role="dialog"]').getByRole('button', { name: /Confirm|Unapply/i });
    if (await confirmBtn.isVisible()) {
      await confirmBtn.click();
    }
    await expect(page.getByText(/unapplied|removed|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Sale Detail: Posting Group Navigation ──────────────────
  test('@all sale detail links to posting group with ledger entries', async ({ page }) => {
    await page.goto('/app/sales', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted sales');
      return;
    }
    await postedRow.click();

    const pgLink = page.getByTestId('posting-group-id');
    if (await pgLink.isVisible()) {
      await pgLink.click();
      await expect(page).toHaveURL(/\/app\/posting-groups\/[0-9a-f-]+/);
      await expect(page.getByTestId('posting-group-panel')).toBeVisible();
      await expect(page.getByTestId('ledger-entries-table')).toBeVisible();
    }
  });
});
