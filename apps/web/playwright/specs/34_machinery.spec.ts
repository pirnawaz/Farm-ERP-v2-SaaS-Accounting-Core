/**
 * @all Machinery module: machines CRUD, work logs, rate cards, charges, maintenance jobs,
 * maintenance types, services (create → post via data-testid), profitability report.
 *
 * ONLY MachineryServiceDetailPage uses data-testid (post-btn, posting-date-input,
 * confirm-post, status-badge, posting-group-id). All other pages use plain buttons + Modal.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `MCH-${Date.now()}`;

test.describe('@all Machinery module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ─── Machines CRUD ───────────────────────────────────────────
  test('@all machines list page loads', async ({ page }) => {
    await page.goto('/app/machinery/machines', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Machines/i);
  });

  test('@all create a new machine', async ({ page }) => {
    await page.goto('/app/machinery/machines', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    await page.getByRole('button', { name: /New Machine/i }).click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();

    await fillByLabel(page, 'Name', `Machine-${runId}`);

    // Code field
    const codeInput = page.locator('label:has-text("Code")').locator('..').locator('input').first();
    if (await codeInput.count() > 0) {
      await codeInput.fill(`MCH-${runId}`);
    }

    // Machine Type (required select)
    const typeSelect = page.locator('label:has-text("Machine Type")').locator('..').locator('select').first();
    if (await typeSelect.count() > 0) {
      await typeSelect.selectOption({ index: 1 });
    }

    // Ownership Type (required select)
    const ownershipSelect = page.locator('label:has-text("Ownership Type")').locator('..').locator('select').first();
    if (await ownershipSelect.count() > 0) {
      await ownershipSelect.selectOption({ index: 1 });
    }

    // Meter Unit (required, options: HOURS/KM)
    const meterSelect = page.locator('label:has-text("Meter Unit")').locator('..').locator('select').first();
    if (await meterSelect.count() > 0) {
      await meterSelect.selectOption('HOURS');
    }

    await dialog.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Work Logs ───────────────────────────────────────────────
  test('@all machinery work logs page loads', async ({ page }) => {
    await page.goto('/app/machinery/work-logs', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Work Log/i);
  });

  test('@all create machinery work log', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/machinery/work-logs/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Machine (required)
    const machineSelect = page.locator('label:has-text("Machine")').locator('..').locator('select').first();
    if (await machineSelect.count() > 0) {
      const optionCount = await machineSelect.locator('option').count();
      if (optionCount <= 1) {
        test.skip(true, 'No machines available for work log');
        return;
      }
      await machineSelect.selectOption({ index: 1 });
    } else {
      test.skip(true, 'No machine select found');
      return;
    }

    // Project (required)
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 1 });
    }

    // Work date
    await fillByLabel(page, 'Work date', today);

    await page.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Rate Cards ──────────────────────────────────────────────
  test('@all rate cards page loads', async ({ page }) => {
    await page.goto('/app/machinery/rate-cards', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Rate Card/i);
  });

  test('@all create a rate card', async ({ page }) => {
    await page.goto('/app/machinery/rate-cards', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    await page.getByRole('button', { name: /New Rate Card/i }).click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();

    // Base Rate (required)
    await fillByLabel(page, 'Base Rate', '150');

    // Effective From (required)
    await fillByLabel(page, 'Effective From', todayISO());

    // Rate Unit (required, options: HOUR/KM/JOB)
    const rateUnitSelect = page.locator('label:has-text("Rate Unit")').locator('..').locator('select').first();
    if (await rateUnitSelect.count() > 0) {
      await rateUnitSelect.selectOption('HOUR');
    }

    // Applies To Mode
    const modeSelect = page.locator('label:has-text("Applies To Mode")').locator('..').locator('select').first();
    if (await modeSelect.count() > 0) {
      await modeSelect.selectOption('MACHINE');
    }

    // Machine select (conditional)
    const machineSelect = page.locator('label:has-text("Machine")').locator('..').locator('select').first();
    if (await machineSelect.count() > 0) {
      await machineSelect.selectOption({ index: 1 });
    }

    await dialog.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Charges ─────────────────────────────────────────────────
  test('@all charges page loads', async ({ page }) => {
    await page.goto('/app/machinery/charges', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Charge/i);
  });

  // ─── Maintenance Types ───────────────────────────────────────
  test('@all maintenance types page loads', async ({ page }) => {
    await page.goto('/app/machinery/maintenance-types', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Maintenance Type/i);
  });

  test('@all create a maintenance type', async ({ page }) => {
    await page.goto('/app/machinery/maintenance-types', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    await page.getByRole('button', { name: /New Maintenance Type/i }).click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Name', `MaintType-${runId}`);

    await dialog.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Maintenance Jobs ────────────────────────────────────────
  test('@all maintenance jobs page loads', async ({ page }) => {
    await page.goto('/app/machinery/maintenance-jobs', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Maintenance Job/i);
  });

  test('@all create a maintenance job', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/machinery/maintenance-jobs/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Machine (required)
    const machineSelect = page.locator('label:has-text("Machine")').locator('..').locator('select').first();
    if (await machineSelect.count() > 0) {
      await machineSelect.selectOption({ index: 1 });
    }

    // Job Date (required)
    await fillByLabel(page, 'Job Date', today);

    // Maintenance Type (optional select)
    const typeSelect = page.locator('label:has-text("Maintenance Type")').locator('..').locator('select').first();
    if (await typeSelect.count() > 0 && (await typeSelect.locator('option').count()) > 1) {
      await typeSelect.selectOption({ index: 1 });
    }

    // Add a line: Description + Amount
    const addLineBtn = page.getByRole('button', { name: /\+ Add line/i });
    if (await addLineBtn.isVisible()) {
      await addLineBtn.click();
      const lastRow = page.locator('table tbody tr, .flex').last();
      const inputs = lastRow.locator('input');
      if (await inputs.count() >= 2) {
        await inputs.nth(0).fill('Oil change');
        await inputs.nth(1).fill('500');
      }
    }

    await page.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });
  });

  // ─── Services (create → post via data-testid) ────────────────
  test('@all services list page loads', async ({ page }) => {
    await page.goto('/app/machinery/services', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Service/i);
  });

  test('@all create service, post, and verify posting group', async ({ page }) => {
    const today = todayISO();

    // Create a service via form page
    await page.goto('/app/machinery/services/new', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Project (required)
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() > 0) {
      const optionCount = await projectSelect.locator('option').count();
      if (optionCount <= 1) {
        test.skip(true, 'No project available for service');
        return;
      }
      await projectSelect.selectOption({ index: 1 });
    } else {
      test.skip(true, 'No project select found');
      return;
    }

    // Machine (required)
    const machineSelect = page.locator('label:has-text("Machine")').locator('..').locator('select').first();
    if (await machineSelect.count() > 0) {
      await machineSelect.selectOption({ index: 1 });
    }

    // Rate card (required)
    const rateSelect = page.locator('label:has-text("Rate card")').locator('..').locator('select').first();
    if (await rateSelect.count() > 0) {
      await rateSelect.selectOption({ index: 1 });
    }

    // Quantity (required)
    await fillByLabel(page, 'Quantity', '3');

    // Allocation scope (required, options: SHARED/HARI_ONLY)
    const scopeSelect = page.locator('label:has-text("Allocation scope")').locator('..').locator('select').first();
    if (await scopeSelect.count() > 0) {
      await scopeSelect.selectOption('SHARED');
    }

    await page.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByText(/created|success/i)).toBeVisible({ timeout: 8000 });

    // Navigate to the created service to post it
    await page.goto('/app/machinery/services', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      return; // No draft to post
    }
    await draftRow.click();

    // MachineryServiceDetailPage uses data-testid for post
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

  // ─── Profitability Report ────────────────────────────────────
  test('@all machinery profitability report loads', async ({ page }) => {
    await page.goto('/app/machinery/reports/profitability', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Profitability|Machinery/i);
  });
});
