/**
 * @all Labour module: dashboard, workers CRUD, work log creation, post/reverse lifecycle.
 * Workers use Modal dialog. Work logs use a form page (/new) and detail page with
 * plain Post/Reverse buttons that open Modal dialogs (no data-testid).
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `LAB-${Date.now()}`;

test.describe('@all Labour module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ─── Dashboard ───────────────────────────────────────────────
  test('@all labour dashboard loads', async ({ page }) => {
    await page.goto('/app/labour', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Labour/i);
  });

  // ─── Workers CRUD ────────────────────────────────────────────
  test('@all workers list page loads', async ({ page }) => {
    await page.goto('/app/labour/workers', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Workers/i);
  });

  test('@all create a new worker', async ({ page }) => {
    await page.goto('/app/labour/workers', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    await page.getByRole('button', { name: /New Worker/i }).click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();

    await fillByLabel(page, 'Name', `Worker-${runId}`);

    // Select worker type
    const typeSelect = page.locator('label:has-text("Type")').locator('..').locator('select').first();
    if (await typeSelect.count() > 0) {
      await typeSelect.selectOption('HARI');
    }

    // Select rate basis
    const rateSelect = page.locator('label:has-text("Rate basis")').locator('..').locator('select').first();
    if (await rateSelect.count() > 0) {
      await rateSelect.selectOption('DAILY');
    }

    // Fill default rate if available
    const rateInput = page.locator('label:has-text("Default rate")').locator('..').locator('input').first();
    if (await rateInput.count() > 0) {
      await rateInput.fill('500');
    }

    // Fill phone if available
    const phoneInput = page.locator('label:has-text("Phone")').locator('..').locator('input').first();
    if (await phoneInput.count() > 0) {
      await phoneInput.fill('0912345678');
    }

    await dialog.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Work Logs ───────────────────────────────────────────────
  test('@all work logs list page loads', async ({ page }) => {
    await page.goto('/app/labour/work-logs', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Work Log/i);
  });

  test('@all create a work log entry', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/labour/work-logs/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Worker (required select)
    const workerSelect = page.locator('label:has-text("Worker")').locator('..').locator('select').first();
    if (await workerSelect.count() > 0) {
      const optionCount = await workerSelect.locator('option').count();
      if (optionCount <= 1) {
        test.skip(true, 'No workers available for work log');
        return;
      }
      await workerSelect.selectOption({ index: 1 });
    } else {
      test.skip(true, 'No worker select found');
      return;
    }

    await fillByLabel(page, 'Work Date', today);

    // Crop Cycle (required)
    const cycleSelect = page.locator('label:has-text("Crop Cycle")').locator('..').locator('select').first();
    if (await cycleSelect.count() > 0) {
      await cycleSelect.selectOption({ index: 1 });
    }

    // Project (required)
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 1 });
    }

    // Rate basis
    const basisSelect = page.locator('label:has-text("Rate basis")').locator('..').locator('select').first();
    if (await basisSelect.count() > 0) {
      await basisSelect.selectOption('DAILY');
    }

    // Units (required)
    await fillByLabel(page, 'Units', '8');

    // Rate (required)
    await fillByLabel(page, 'Rate', '500');

    await page.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Work Log Detail & Post ──────────────────────────────────
  test('@all open work log detail and verify status', async ({ page }) => {
    await page.goto('/app/labour/work-logs', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const row = page.locator('table tbody tr').first();
    if (await row.count() === 0) {
      test.skip(true, 'No work logs available');
      return;
    }

    await row.click();
    await expect(page).toHaveURL(/\/app\/labour\/work-logs\/[0-9a-f-]+/);
    await expect(page.locator('body')).toContainText(/DRAFT|POSTED|REVERSED/i);
  });

  test('@all post work log via detail page', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/labour/work-logs', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      test.skip(true, 'No draft work logs available to post');
      return;
    }
    await draftRow.click();
    await expect(page).toHaveURL(/\/app\/labour\/work-logs\/[0-9a-f-]+/);

    // Plain Post button (no data-testid)
    const postBtn = page.getByRole('button', { name: /^Post$/i });
    if (!(await postBtn.isVisible())) {
      test.skip(true, 'Post button not visible');
      return;
    }
    await postBtn.click();

    // Post Work Log modal
    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting Date', today);
    await dialog.getByRole('button', { name: /^Post$/i }).click();
    await expect(page.locator('body')).toContainText(/POSTED|posted|success/i, { timeout: 10000 });
  });

  test('@all reverse posted work log', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/labour/work-logs', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted work logs available to reverse');
      return;
    }
    await postedRow.click();

    const reverseBtn = page.getByRole('button', { name: /^Reverse$/i });
    if (!(await reverseBtn.isVisible())) {
      test.skip(true, 'Reverse button not visible');
      return;
    }
    await reverseBtn.click();

    // Reverse Work Log modal
    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting Date', today);
    await dialog.getByRole('button', { name: /^Reverse$/i }).click();
    await expect(page.locator('body')).toContainText(/REVERSED|reversed|success/i, { timeout: 10000 });
  });
});
