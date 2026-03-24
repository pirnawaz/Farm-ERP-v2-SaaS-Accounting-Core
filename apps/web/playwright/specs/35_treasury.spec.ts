/**
 * @all Treasury module: Payments lifecycle (create → post → reverse), Advances lifecycle,
 * apply/unapply payments to sales, allocation preview.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `TRS-${Date.now()}`;

test.describe('@all Treasury module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ═══════════════════════════════════════════════════════════════
  // PAYMENTS
  // ═══════════════════════════════════════════════════════════════

  test('@all payments list page loads', async ({ page }) => {
    await page.goto('/app/payments', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Payment/i);
  });

  test('@all create inbound payment (CASH)', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/payments/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // PaymentFormPage fields: Direction, Party, Amount, Payment Date, Method, Reference
    await selectByLabelOption(page, 'Direction', 'IN');

    const partySelect = page.locator('label:has-text("Party")').locator('..').locator('select').first();
    if (await partySelect.count() > 0) {
      await partySelect.selectOption({ index: 1 });
    }

    await fillByLabel(page, 'Amount', '500');
    await fillByLabel(page, 'Payment Date', today);
    await selectByLabelOption(page, 'Method', 'CASH');
    await fillByLabel(page, 'Reference', `PAY-IN-${runId}`);

    await page.getByRole('button', { name: /Save|Create/i }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 8000 });
  });

  test('@all create outbound payment (BANK)', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/payments/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    await selectByLabelOption(page, 'Direction', 'Outbound');

    const partySelect = page.locator('label:has-text("Party")').locator('..').locator('select').first();
    if (await partySelect.count() > 0) {
      await partySelect.selectOption({ index: 1 });
    }

    await fillByLabel(page, 'Amount', '250');
    await fillByLabel(page, 'Payment Date', today);
    await selectByLabelOption(page, 'Method', 'Bank');
    await fillByLabel(page, 'Reference', `PAY-OUT-${runId}`);

    await page.getByRole('button', { name: /Save|Create/i }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 8000 });
  });

  test('@all post payment and verify posting group', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/payments', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      test.skip(true, 'No draft payments to post');
      return;
    }
    await draftRow.click();
    await expect(page).toHaveURL(/\/app\/payments\/[0-9a-f-]+/);

    await page.getByTestId('post-btn').click();
    await page.getByTestId('posting-date-input').fill(today);
    await page.getByTestId('confirm-post').click();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('POSTED', {
      timeout: 10000,
    });
    await expect(page.getByTestId('posting-group-id')).toBeVisible();
  });

  test('@all reverse posted payment', async ({ page }) => {
    await page.goto('/app/payments', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted payments to reverse');
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

  test('@all payment detail shows allocation rows table', async ({ page }) => {
    await page.goto('/app/payments', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted payments to check allocation rows');
      return;
    }
    await postedRow.click();

    // Posting group should show allocation rows
    const pgLink = page.getByTestId('posting-group-id');
    if (await pgLink.isVisible()) {
      await pgLink.click();
      await expect(page.getByTestId('posting-group-panel')).toBeVisible();
      await expect(page.getByTestId('allocation-rows-table')).toBeVisible();
      await expect(page.getByTestId('ledger-entries-table')).toBeVisible();
    }
  });

  // ═══════════════════════════════════════════════════════════════
  // ADVANCES
  // ═══════════════════════════════════════════════════════════════

  test('@all advances list page loads', async ({ page }) => {
    await page.goto('/app/advances', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Advance/i);
  });

  test('@all create an advance', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/advances/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Select party
    const partySelect = page.locator('label:has-text("Party")').locator('..').locator('select').first();
    if (await partySelect.count() > 0) {
      await partySelect.selectOption({ index: 1 });
    }

    await fillByLabel(page, 'Amount', '1000');
    await fillByLabel(page, 'Date', today);
    await fillByLabel(page, 'Reference', `ADV-${runId}`);

    await page.getByRole('button', { name: /Save|Create/i }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 8000 });
  });

  test('@all post advance and verify posting group', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/advances', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      test.skip(true, 'No draft advances to post');
      return;
    }
    await draftRow.click();
    await expect(page).toHaveURL(/\/app\/advances\/[0-9a-f-]+/);

    const postBtn = page.getByTestId('post-btn');
    if (await postBtn.isVisible()) {
      await postBtn.click();
      await page.getByTestId('posting-date-input').fill(today);
      await page.getByTestId('confirm-post').click();
      await expect(page.locator('[data-testid="status-badge"]')).toContainText('POSTED', {
        timeout: 10000,
      });
      await expect(page.getByTestId('posting-group-id')).toBeVisible();
    }
  });
});
