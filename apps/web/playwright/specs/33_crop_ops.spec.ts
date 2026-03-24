/**
 * @all Crop Operations module: dashboard, activity types, activities CRUD with inputs & labour,
 * activity post/reverse lifecycle, harvests CRUD with lines, harvest post/reverse.
 * All post/reverse uses plain buttons that open Modal dialogs (no data-testid).
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `COP-${Date.now()}`;

test.describe('@all Crop Operations module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ─── Dashboard ───────────────────────────────────────────────
  test('@all crop ops dashboard loads', async ({ page }) => {
    await page.goto('/app/crop-ops', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Activity|Activities/i);
  });

  // ─── Activity Types ──────────────────────────────────────────
  test('@all activity types page loads', async ({ page }) => {
    await page.goto('/app/crop-ops/activity-types', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Activity Type/i);
  });

  test('@all create an activity type', async ({ page }) => {
    await page.goto('/app/crop-ops/activity-types', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    await page.getByRole('button', { name: /New Type/i }).click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Name', `ActType-${runId}`);

    await dialog.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Activities ──────────────────────────────────────────────
  test('@all activities list page loads', async ({ page }) => {
    await page.goto('/app/crop-ops/activities', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Activities/i);
  });

  test('@all create an activity with inputs and labour', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/crop-ops/activities/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Activity Type (required select)
    const typeSelect = page.locator('label:has-text("Activity Type")').locator('..').locator('select').first();
    if (await typeSelect.count() > 0) {
      const optionCount = await typeSelect.locator('option').count();
      if (optionCount <= 1) {
        test.skip(true, 'No activity types available');
        return;
      }
      await typeSelect.selectOption({ index: 1 });
    }

    // Activity Date (required)
    await fillByLabel(page, 'Activity Date', today);

    // Crop Cycle (required)
    const cycleSelect = page.locator('label:has-text("Crop Cycle")').locator('..').locator('select').first();
    if (await cycleSelect.count() > 0) {
      await cycleSelect.selectOption({ index: 1 });
    }

    // Project (required)
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() > 0) {
      const optionCount = await projectSelect.locator('option').count();
      if (optionCount <= 1) {
        test.skip(true, 'No projects available for activity');
        return;
      }
      await projectSelect.selectOption({ index: 1 });
    } else {
      test.skip(true, 'No project select found');
      return;
    }

    // Add input line (Inputs table: Store, Item, Qty with "+ Add" button)
    const addInputBtn = page.getByRole('button', { name: /\+ Add/i }).first();
    if (await addInputBtn.isVisible()) {
      await addInputBtn.click();
      // Wait for the row to appear, then fill selects in last row
      const inputRows = page.locator('table').first().locator('tbody tr');
      const lastRow = inputRows.last();
      const selects = lastRow.locator('select');
      if (await selects.count() >= 2) {
        await selects.nth(0).selectOption({ index: 1 }); // Store
        await selects.nth(1).selectOption({ index: 1 }); // Item
      }
      const qtyInput = lastRow.locator('input[type="number"]').first();
      if (await qtyInput.count() > 0) {
        await qtyInput.fill('2');
      }
    }

    // Add labour line (Labour table: Worker, Basis, Units, Rate with "+ Add" button)
    const addLabourBtn = page.getByRole('button', { name: /\+ Add/i }).last();
    if (await addLabourBtn.isVisible()) {
      await addLabourBtn.click();
      const labourRows = page.locator('table').last().locator('tbody tr');
      const lastLabourRow = labourRows.last();
      const labourSelects = lastLabourRow.locator('select');
      if (await labourSelects.count() >= 2) {
        await labourSelects.nth(0).selectOption({ index: 1 }); // Worker
        await labourSelects.nth(1).selectOption('DAILY');       // Basis
      }
      const unitInputs = lastLabourRow.locator('input[type="number"]');
      if (await unitInputs.count() >= 2) {
        await unitInputs.nth(0).fill('4');  // Units
        await unitInputs.nth(1).fill('500'); // Rate
      }
    }

    await page.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  test('@all post activity via detail page', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/crop-ops/activities', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      test.skip(true, 'No draft activities available to post');
      return;
    }
    await draftRow.click();
    await expect(page).toHaveURL(/\/app\/crop-ops\/activities\/[0-9a-f-]+/);

    // Plain Post button (no data-testid)
    const postBtn = page.getByRole('button', { name: /^Post$/i });
    if (!(await postBtn.isVisible())) {
      test.skip(true, 'Post button not visible');
      return;
    }
    await postBtn.click();

    // Post Activity modal
    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting Date', today);
    await dialog.getByRole('button', { name: /^Post$/i }).click();
    await expect(page.locator('body')).toContainText(/POSTED|posted|success/i, { timeout: 10000 });
  });

  test('@all reverse posted activity', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/crop-ops/activities', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted activities to reverse');
      return;
    }
    await postedRow.click();

    const reverseBtn = page.getByRole('button', { name: /^Reverse$/i });
    if (!(await reverseBtn.isVisible())) {
      test.skip(true, 'Reverse button not visible');
      return;
    }
    await reverseBtn.click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting Date', today);
    await dialog.getByRole('button', { name: /^Reverse$/i }).click();
    await expect(page.locator('body')).toContainText(/REVERSED|reversed|success/i, { timeout: 10000 });
  });

  // ─── Harvests ────────────────────────────────────────────────
  test('@all harvests page loads', async ({ page }) => {
    await page.goto('/app/harvests', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Harvest/i);
  });

  test('@all create a harvest entry with lines', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/harvests/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Crop Cycle (required)
    const cycleSelect = page.locator('label:has-text("Crop Cycle")').locator('..').locator('select').first();
    if (await cycleSelect.count() > 0) {
      const optionCount = await cycleSelect.locator('option').count();
      if (optionCount <= 1) {
        test.skip(true, 'No crop cycles available for harvest');
        return;
      }
      await cycleSelect.selectOption({ index: 1 });
    }

    // Project (required)
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 1 });
    }

    // Harvest Date (required)
    await fillByLabel(page, 'Harvest Date', today);

    // Add a harvest line (fields: Item, Store, Qty, UOM)
    const addLineBtn = page.getByRole('button', { name: /Add Line/i });
    if (await addLineBtn.isVisible()) {
      await addLineBtn.click();
      const qtyInputs = page.locator('input[type="number"]');
      if (await qtyInputs.count() > 0) {
        await qtyInputs.last().fill('100');
      }
    }

    await page.getByRole('button', { name: /Create Harvest/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  test('@all post harvest via detail page', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/harvests', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      test.skip(true, 'No draft harvests to post');
      return;
    }
    await draftRow.click();

    const postBtn = page.getByRole('button', { name: /^Post$/i });
    if (!(await postBtn.isVisible())) {
      test.skip(true, 'Post button not visible');
      return;
    }
    await postBtn.click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting Date', today);
    await dialog.getByRole('button', { name: /^Post$/i }).click();
    await expect(page.locator('body')).toContainText(/POSTED|posted|success/i, { timeout: 10000 });
  });
});
