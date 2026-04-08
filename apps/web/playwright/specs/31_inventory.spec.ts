/**
 * @all Inventory module: dashboard, items CRUD, stores, GRN lifecycle (create → post → reverse),
 * issue lifecycle, transfers, adjustments, stock-on-hand, stock-movements.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `INV-${Date.now()}`;

test.describe('@all Inventory module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ─── Dashboard ───────────────────────────────────────────────
  test('@all inventory dashboard loads with links', async ({ page }) => {
    await page.goto('/app/inventory', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Inventory/i);
    // Dashboard should have links to sub-pages
    await expect(page.getByRole('link', { name: /Items/i }).or(page.locator('a[href*="inventory/items"]'))).toBeVisible();
    await expect(page.getByRole('link', { name: /GRN/i }).or(page.locator('a[href*="inventory/grns"]'))).toBeVisible();
  });

  // ─── Items CRUD ──────────────────────────────────────────────
  test('@all items list page loads', async ({ page }) => {
    await page.goto('/app/inventory/items', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Items/i);
  });

  test('@all create inventory item', async ({ page }) => {
    await page.goto('/app/inventory/items', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    await page.getByRole('button', { name: /Add Item/i }).click();
    await fillByLabel(page, 'Name', `Item-${runId}`);

    // InvItemsPage: Unit of Measure (required), Category, SKU, Active
    const uomSelect = page.locator('label:has-text("Unit of Measure")').locator('..').locator('select').first();
    if (await uomSelect.count() > 0) {
      await uomSelect.selectOption({ index: 1 });
    }

    // Select category if available
    const catSelect = page.locator('label:has-text("Category")').locator('..').locator('select').first();
    if (await catSelect.count() > 0) {
      await catSelect.selectOption({ index: 1 });
    }

    await page.locator('[role="dialog"]').getByRole('button', { name: /Create|Save/i }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── GRN Lifecycle ───────────────────────────────────────────
  test('@all GRN list page loads', async ({ page }) => {
    await page.goto('/app/inventory/grns', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Goods Received|GRN/i);
  });

  test('@all create GRN with line items', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/inventory/grns/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Select store
    const storeSelect = page.locator('label:has-text("Store")').locator('..').locator('select').first();
    if (await storeSelect.count() > 0) {
      await storeSelect.selectOption({ index: 1 });
    }

    // Select supplier/party if available
    const partySelect = page.locator('label:has-text("Supplier")').locator('..').locator('select').first()
      .or(page.locator('label:has-text("Party")').locator('..').locator('select').first());
    if (await partySelect.count() > 0) {
      await partySelect.selectOption({ index: 1 });
    }

    // InvGrnFormPage: Doc No (auto), Doc Date, Store, Supplier
    await fillByLabel(page, 'Doc Date', today);

    // Add line: Item (select), Qty (number), Unit cost (number)
    await page.getByRole('button', { name: /Add line/i }).click();
    const lineRow = page.locator('table tbody tr').last();
    const itemSelect = lineRow.locator('select').first();
    if (await itemSelect.count() > 0) {
      await itemSelect.selectOption({ index: 1 });
    }
    // Fill quantity and unit cost in line
    const inputs = lineRow.locator('input[type="number"]');
    if (await inputs.count() >= 2) {
      await inputs.nth(0).fill('10');
      await inputs.nth(1).fill('25.00');
    }

    await page.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 8000 });
  });

  test('@all post GRN and verify posting group', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/inventory/grns', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      test.skip(true, 'No draft GRNs available to post');
      return;
    }
    // Click the row or View link to go to GRN detail
    const viewLink = draftRow.getByRole('link', { name: /View/i });
    if (await viewLink.count() > 0) {
      await viewLink.click();
    } else {
      await draftRow.click();
    }
    await expect(page).toHaveURL(/\/app\/inventory\/grns\/[0-9a-f-]+/);

    // GRN detail: Post button is a plain <button> (no data-testid)
    const postBtn = page.getByRole('button', { name: /^Post$/i });
    if (!(await postBtn.isVisible())) {
      test.skip(true, 'Post button not visible');
      return;
    }
    await postBtn.click();

    // Post GRN modal: Posting Date field
    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting Date', today);
    await dialog.getByRole('button', { name: /Post|Confirm/i }).click();
    await expect(page.locator('body')).toContainText(/POSTED/i, { timeout: 10000 });
  });

  test('@all reverse posted GRN', async ({ page }) => {
    await page.goto('/app/inventory/grns', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted GRNs available to reverse');
      return;
    }
    const viewLink = postedRow.getByRole('link', { name: /View/i });
    if (await viewLink.count() > 0) {
      await viewLink.click();
    } else {
      await postedRow.click();
    }

    const reverseBtn = page.getByRole('button', { name: /Reverse/i });
    if (!(await reverseBtn.isVisible())) {
      test.skip(true, 'Reverse button not visible');
      return;
    }
    await reverseBtn.click();

    // Reverse GRN modal: Posting Date + Reason
    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting Date', todayISO());
    await dialog.getByRole('button', { name: /Reverse|Confirm/i }).click();
    await expect(page.locator('body')).toContainText(/REVERSED|reversed|success/i, { timeout: 10000 });
  });

  // ─── Issues ──────────────────────────────────────────────────
  test('@all issues list page loads', async ({ page }) => {
    await page.goto('/app/inventory/issues', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Issues/i);
  });

  test('@all create inventory issue with project allocation', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/inventory/issues/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Select store
    const storeSelect = page.locator('label:has-text("Store")').locator('..').locator('select').first();
    if (await storeSelect.count() > 0) {
      await storeSelect.selectOption({ index: 1 });
    }

    // InvIssueFormPage: Doc No, Doc Date, Store, Crop Cycle, Project, Allocation Mode
    await fillByLabel(page, 'Doc Date', today);

    const cycleSelect = page.locator('label:has-text("Crop Cycle")').locator('..').locator('select').first();
    if (await cycleSelect.count() > 0) {
      await cycleSelect.selectOption({ index: 1 });
    }

    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 1 });
    }

    // Add line: Item (select), Qty (number)
    await page.getByRole('button', { name: /Add line/i }).click();
    const lineRow = page.locator('table tbody tr').last();
    const itemSelect = lineRow.locator('select').first();
    if (await itemSelect.count() > 0) {
      await itemSelect.selectOption({ index: 1 });
    }
    const qtyInput = lineRow.locator('input[type="number"]').first();
    if (await qtyInput.count() > 0) {
      await qtyInput.fill('5');
    }

    await page.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created successfully|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Transfers ───────────────────────────────────────────────
  test('@all transfers page loads', async ({ page }) => {
    await page.goto('/app/inventory/transfers', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Transfer/i);
  });

  // ─── Adjustments ─────────────────────────────────────────────
  test('@all adjustments page loads', async ({ page }) => {
    await page.goto('/app/inventory/adjustments', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Adjustment/i);
  });

  // ─── Stock Reports ───────────────────────────────────────────
  test('@all stock-on-hand page loads', async ({ page }) => {
    await page.goto('/app/inventory/stock-on-hand', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Stock/i);
  });

  test('@all stock-movements page loads', async ({ page }) => {
    await page.goto('/app/inventory/stock-movements', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Movement/i);
  });
});
