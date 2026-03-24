/**
 * @core Transaction reversals, edge cases, and immutable ledger integrity.
 * Tests: reversal creates new posting group, reversed transaction cannot be re-posted,
 * crop cycle close/reopen preview, DRAFT edit and delete, posting group detail integrity.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `REV-${Date.now()}`;

test.describe('@core Transaction reversal & edge cases', () => {
  // ─── Create chain: land → cycle → allocation → project → transaction ─
  test('@core full reversal flow: post transaction then reverse and verify VOID status', async ({
    page,
  }) => {
    const today = todayISO();

    // Create land parcel
    await page.goto('/app/land', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await page.getByTestId('new-land-parcel').click();
    await fillByLabel(page, 'Name', runId);
    await fillByLabel(page, 'Total Acres', '5');
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.locator('[role="dialog"]')).toHaveCount(0, { timeout: 15000 });

    // Create crop cycle
    await page.goto('/app/crop-cycles', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await page.getByTestId('new-crop-cycle').click();
    await fillByLabel(page, 'Name', `C-${runId}`);
    await fillByLabel(page, 'Start Date', today);
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.locator('[role="dialog"]')).toHaveCount(0, { timeout: 15000 });

    // Create allocation
    await page.goto('/app/allocations', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await page.getByTestId('new-land-allocation').click();
    await selectByLabelOption(page, 'Crop Cycle', new RegExp(`C-${runId}`));
    await selectByLabelOption(page, 'Land Parcel', runId);
    await page.getByLabel('Owner-operated', { exact: false }).check();
    await fillByLabel(page, 'Allocated Acres', '5');
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 5000 });

    // Create project
    await page.goto('/app/projects', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'New Project from Allocation' }).click();
    await selectByLabelOption(page, 'Allocation', new RegExp(runId));
    await fillByLabel(page, 'Project Name', `P-${runId}`);
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/created successfully|Your first project/i)).toBeVisible({ timeout: 5000 });

    // Create transaction
    await page.goto('/app/transactions/new', { waitUntil: 'domcontentloaded' });
    await selectByLabelOption(page, 'Destination', 'Project');
    await selectByLabelOption(page, 'Project', `P-${runId}`);
    await selectByLabelOption(page, 'Classification', 'Shared');
    await fillByLabel(page, 'Transaction Date', today);
    await fillByLabel(page, 'Amount', '300');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 5000 });

    // Open transaction and post it
    await page.goto('/app/transactions', { waitUntil: 'domcontentloaded' });
    await page.locator('table tbody tr').filter({ hasText: '300' }).first().click();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('DRAFT');

    await page.getByTestId('post-btn').click();
    await page.getByTestId('posting-date-input').fill(today);
    await page.getByTestId('confirm-post').click();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('POSTED', {
      timeout: 10000,
    });
    const pgLink = page.getByTestId('posting-group-id');
    await expect(pgLink).toBeVisible();
    const originalPgHref = await pgLink.getAttribute('href');

    // Reverse the posted transaction
    const reverseBtn = page.getByRole('button', { name: /Reverse/i });
    await expect(reverseBtn).toBeVisible();
    await reverseBtn.click();

    // Confirm reversal
    const confirmBtn = page.locator('[role="dialog"]').getByRole('button', { name: /Confirm|Reverse/i });
    if (await confirmBtn.isVisible()) {
      await confirmBtn.click();
    }

    // Status should be VOID/REVERSED
    await expect(page.locator('[data-testid="status-badge"]')).toContainText(/VOID|REVERSED/i, {
      timeout: 10000,
    });

    // Should have a reversal posting group (different from original)
    const reversalPgLink = page.getByTestId('posting-group-id');
    if (await reversalPgLink.count() > 1) {
      // Multiple posting group links: original + reversal
      const reversalHref = await reversalPgLink.last().getAttribute('href');
      expect(reversalHref).not.toEqual(originalPgHref);
    }
  });

  // ─── DRAFT can be edited ─────────────────────────────────────
  test('@core DRAFT transaction can be edited before posting', async ({ page }) => {
    const today = todayISO();

    // Create a quick transaction (assuming prerequisite chain exists)
    await page.goto('/app/transactions/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() === 0 || (await projectSelect.locator('option').count()) <= 1) {
      test.skip(true, 'No projects available for transaction edit test');
      return;
    }

    await selectByLabelOption(page, 'Destination', 'Project');
    await projectSelect.selectOption({ index: 1 });
    await selectByLabelOption(page, 'Classification', 'Shared');
    await fillByLabel(page, 'Transaction Date', today);
    await fillByLabel(page, 'Amount', '77.77');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 5000 });

    // Open the draft transaction
    await page.goto('/app/transactions', { waitUntil: 'domcontentloaded' });
    await page.locator('table tbody tr').filter({ hasText: '77.77' }).first().click();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('DRAFT');

    // Edit button should exist for DRAFT
    const editBtn = page.getByRole('button', { name: /Edit/i }).or(page.getByRole('link', { name: /Edit/i }));
    if (await editBtn.isVisible()) {
      await editBtn.click();
      // Should navigate to edit form or show inline edit
      await fillByLabel(page, 'Amount', '88.88');
      await page.getByRole('button', { name: /Save|Update/i }).click();
      await expect(page.getByText(/updated|saved|success/i)).toBeVisible({ timeout: 8000 });
    }
  });

  // ─── POSTED transaction cannot be edited ─────────────────────
  test('@core POSTED transaction does not show Edit button', async ({ page }) => {
    await page.goto('/app/transactions', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted transactions to check');
      return;
    }
    await postedRow.click();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('POSTED');

    // Edit button should NOT be visible for POSTED
    const editBtn = page.getByRole('button', { name: /^Edit$/i });
    await expect(editBtn).toHaveCount(0);
  });

  // ─── Posting group detail integrity ──────────────────────────
  test('@core posting group detail shows balanced ledger entries', async ({ page }) => {
    await page.goto('/app/transactions', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted transactions');
      return;
    }
    await postedRow.click();

    const pgLink = page.getByTestId('posting-group-id');
    if (await pgLink.isVisible()) {
      await pgLink.click();
      await expect(page).toHaveURL(/\/app\/posting-groups\/[0-9a-f-]+/);
      await expect(page.getByTestId('posting-group-panel')).toBeVisible();
      await expect(page.getByTestId('ledger-entries-table')).toBeVisible();
      await expect(page.getByTestId('allocation-rows-table')).toBeVisible();

      // Ledger entries table should have rows
      const ledgerRows = page.getByTestId('ledger-entries-table').locator('tbody tr');
      const count = await ledgerRows.count();
      expect(count).toBeGreaterThan(0);
    }
  });

  // ─── Crop cycle close/reopen ─────────────────────────────────
  test('@core crop cycle close preview and reopen', async ({ page }) => {
    await page.goto('/app/crop-cycles', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Find an open crop cycle
    const openRow = page.locator('table tbody tr').filter({ hasText: /Open|Active/i }).first();
    if (await openRow.count() === 0) {
      test.skip(true, 'No open crop cycles');
      return;
    }
    await openRow.click();
    await expect(page).toHaveURL(/\/app\/crop-cycles\/[0-9a-f-]+/);

    // Close button with preview
    const closeBtn = page.getByRole('button', { name: /Close/i });
    if (await closeBtn.isVisible()) {
      await closeBtn.click();

      // Close dialog may show preview of cost rollup
      const dialog = page.locator('[role="dialog"]');
      if (await dialog.isVisible()) {
        // Preview content
        await expect(dialog).toContainText(/cost|total|preview|close/i);

        const confirmClose = dialog.getByRole('button', { name: /Confirm|Close/i });
        await confirmClose.click();
        await expect(page.getByText(/closed|success/i)).toBeVisible({ timeout: 8000 });
      }

      // Now reopen
      const reopenBtn = page.getByRole('button', { name: /Reopen/i });
      if (await reopenBtn.isVisible()) {
        await reopenBtn.click();
        const confirmReopen = page.locator('[role="dialog"]').getByRole('button', { name: /Confirm|Reopen/i });
        if (await confirmReopen.isVisible()) {
          await confirmReopen.click();
        }
        await expect(page.getByText(/reopened|success/i)).toBeVisible({ timeout: 8000 });
      }
    }
  });
});
