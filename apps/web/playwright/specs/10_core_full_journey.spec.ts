/**
 * Full end-to-end core journey: land → crop cycle → allocation → project → rules → transaction → post → posting group.
 * @core Requires E2E_PROFILE=core (land, projects_crop_cycles, reports only). No seed shortcuts for these entities.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `E2E-${Date.now()}`;

test.describe('@core Core full journey', () => {
  test('@core create land → crop cycle → allocation → project → rules → transaction → post → posting group', async ({
    page,
  }) => {
    const today = todayISO();

    // --- Land: create parcel
    await page.goto('/app/land', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page).toHaveURL(/\/app\/land/);
    await expect(page.getByTestId('app-sidebar')).toBeVisible();
    await expect(page.getByTestId('land-parcels-page')).toBeVisible();
    await page.getByTestId('new-land-parcel').click();
    await fillByLabel(page, 'Name', runId);
    await fillByLabel(page, 'Total Acres', '10');
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.locator('[role="dialog"]')).toHaveCount(0, { timeout: 15000 });
    await expect(page.getByTestId('land-parcels-page').getByText(runId)).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(300);

    // --- Crop cycle: create
    await page.goto('/app/crop-cycles', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/app\/crop-cycles/);
    await page.getByRole('button', { name: 'New Crop Cycle' }).click();
    await fillByLabel(page, 'Name', `Cycle-${runId}`);
    await fillByLabel(page, 'Start Date', today);
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText('Crop cycle created successfully')).toBeVisible({ timeout: 5000 });
    await page.waitForTimeout(300);

    // --- Allocations: create (link land + crop cycle, owner-operated)
    await page.goto('/app/allocations', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page).toHaveURL(/\/app\/allocations/);
    await expect(page.getByTestId('app-sidebar')).toBeVisible();
    await page.getByTestId('new-land-allocation').click();
    await selectByLabelOption(page, 'Crop Cycle', `Cycle-${runId}`);
    await selectByLabelOption(page, 'Land Parcel', runId);
    await page.getByLabel('Owner-operated', { exact: false }).check();
    await fillByLabel(page, 'Allocated Acres', '10');
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText('Land allocation created successfully').or(page.getByText('created successfully'))).toBeVisible({ timeout: 5000 });
    await page.waitForTimeout(300);

    // --- Projects: create from allocation
    await page.goto('/app/projects', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/app\/projects/);
    await page.getByRole('button', { name: 'New Project from Allocation' }).click();
    await selectByLabelOption(page, 'Allocation', new RegExp(runId));
    await fillByLabel(page, 'Project Name', `Project-${runId}`);
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(
      page.getByText('Project created successfully').or(page.getByText('Your first project'))
    ).toBeVisible({ timeout: 5000 });
    await page.waitForTimeout(300);

    // --- Project rules: save (defaults)
    await page.goto('/app/projects', { waitUntil: 'domcontentloaded' });
    await page.locator('table tbody tr').filter({ hasText: `Project-${runId}` }).first().click();
    await expect(page).toHaveURL(/\/app\/projects\/.+/);
    const projectUrl = page.url();
    const projectId = projectUrl.split('/').filter(Boolean).pop();
    await page.goto(`/app/projects/${projectId}/rules`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Project rules updated successfully')).toBeVisible({ timeout: 5000 });

    // --- Transaction: create DRAFT
    await page.goto('/app/transactions/new', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/app\/transactions\/new/);
    await selectByLabelOption(page, 'Destination', 'Project');
    await selectByLabelOption(page, 'Project', `Project-${runId}`);
    await selectByLabelOption(page, 'Classification', 'Shared');
    await fillByLabel(page, 'Transaction Date', today);
    await fillByLabel(page, 'Amount', '100.50');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page).toHaveURL(/\/app\/transactions/);
    await expect(page.getByText('Transaction created successfully')).toBeVisible({ timeout: 5000 });

    // --- Go to transaction list and open the created transaction (first row or by amount)
    await page.goto('/app/transactions', { waitUntil: 'domcontentloaded' });
    await page.locator('table tbody tr').filter({ hasText: '100.50' }).first().click();
    await expect(page).toHaveURL(/\/app\/transactions\/[0-9a-f-]+/);
    await expect(page.locator('[data-testid="transaction-detail"]')).toBeVisible();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('DRAFT');

    // --- Post transaction
    await page.getByTestId('post-btn').click();
    await page.getByTestId('posting-date-input').fill(today);
    await page.getByTestId('confirm-post').click();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('POSTED', {
      timeout: 10000,
    });
    await expect(page.getByTestId('posting-group-id')).toBeVisible();

    const postingGroupLink = page.getByTestId('posting-group-id');
    await postingGroupLink.click();
    await expect(page).toHaveURL(/\/app\/posting-groups\/[0-9a-f-]+/);
    await expect(page.getByTestId('posting-group-panel')).toBeVisible();
    await expect(page.getByTestId('allocation-rows-table')).toBeVisible();
    await expect(page.getByTestId('ledger-entries-table')).toBeVisible();
  });
});
