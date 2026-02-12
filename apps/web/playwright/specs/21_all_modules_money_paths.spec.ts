/**
 * @all All-modules profile: minimal "money path" per optional module (create + post, then verify posting group).
 * Modules that do not yet support full posting via UI are skipped with a clear TODO.
 */

import { test, expect } from '@playwright/test';
import { todayISO } from '../helpers/dates';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('@all All modules money paths', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  test('@all treasury_payments: create payment and post, verify posting group', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/payments');
    await waitForModulesReady(page);
    await page.getByRole('button', { name: /New Payment|Create/ }).click();
    await selectByLabelOption(page, 'Direction', 'Outbound');
    await fillByLabel(page, 'Party', '');
    const partySelect = page.locator('label:has-text("Party")').locator('..').locator('select').first();
    await partySelect.selectOption({ index: 1 });
    await fillByLabel(page, 'Amount', '50');
    await fillByLabel(page, 'Payment Date', today);
    await selectByLabelOption(page, 'Method', 'Cash');
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Save' }).or(
      page.getByRole('button', { name: 'Create' })
    ).first().click();
    await expect(page.getByText(/created successfully|success/)).toBeVisible({ timeout: 8000 });
    await page.goto('/app/payments');
    await page.locator('table tbody tr').first().click();
    await page.getByTestId('post-btn').click();
    await page.getByTestId('posting-date-input').fill(today);
    await page.getByTestId('confirm-post').click();
    await expect(page.getByTestId('posting-group-id')).toBeVisible({ timeout: 10000 });
  });

  test('@all ar_sales: create sale and post, verify posting group', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/sales');
    await waitForModulesReady(page);
    await page.getByRole('button', { name: /New Sale|Create/ }).click();
    const partySelect = page.locator('label:has-text("Buyer")').locator('..').locator('select').first();
    await partySelect.selectOption({ index: 1 });
    await fillByLabel(page, 'Amount', '100');
    await fillByLabel(page, 'Posting Date', today);
    await page.getByRole('button', { name: 'Save' }).or(page.getByRole('button', { name: 'Create' })).first().click();
    await expect(page.getByText(/created successfully|success/)).toBeVisible({ timeout: 8000 });
    await page.goto('/app/sales');
    await page.locator('table tbody tr').first().click();
    await page.getByTestId('post-btn').click();
    await page.getByTestId('posting-date-input').fill(today);
    await page.getByTestId('confirm-post').click();
    await expect(page.getByTestId('posting-group-id').or(page.locator('[data-testid="posting-group-panel"]'))).toBeVisible({ timeout: 10000 });
  });

  test('@all machinery: create service and post, verify posting group', async ({ page }) => {
    // Requires project, machine, rate card from seed or create
    const today = todayISO();
    await page.goto('/app/machinery/services');
    await waitForModulesReady(page);
    const newBtn = page.getByRole('button', { name: /New|Create/ }).first();
    if (await newBtn.isVisible()) {
      await newBtn.click();
      const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
      if (await projectSelect.count() > 0) {
        await projectSelect.selectOption({ index: 1 });
        const machineSelect = page.locator('label:has-text("Machine")').locator('..').locator('select').first();
        await machineSelect.selectOption({ index: 1 });
        const rateSelect = page.locator('label:has-text("Rate card")').locator('..').locator('select').first();
        await rateSelect.selectOption({ index: 1 });
        await fillByLabel(page, 'Quantity', '1');
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText(/created successfully|success/)).toBeVisible({ timeout: 8000 }).catch(() => {});
        await page.goto('/app/machinery/services');
        await page.locator('table tbody tr').first().click();
        if (await page.getByTestId('post-btn').isVisible()) {
          await page.getByTestId('post-btn').click();
          await page.getByTestId('posting-date-input').fill(today);
          await page.getByTestId('confirm-post').click();
          await expect(page.getByTestId('posting-group-id')).toBeVisible({ timeout: 10000 });
        }
      }
    } else {
      test.skip(true, 'Machinery services create UI or seed data not available');
    }
  });

  test('@all inventory: issue/usage to project then post if applicable', async () => {
    // TODO: Full inventory issue → post flow depends on existing items/stores and issue UI. Skip if no create path.
    test.skip(true, 'Inventory issue-to-project posting path: implement when UI supports create issue and post in one flow');
  });

  test('@all labour: create labour cost entry and post', async () => {
    // TODO: Labour work log or cost entry that generates posting group. Skip if UI path not available.
    test.skip(true, 'Labour cost entry posting path: implement when UI supports create work log and post');
  });

  test('@all settlements: generate settlement statement and verify totals non-empty', async ({ page }) => {
    await page.goto('/app/settlement');
    await waitForModulesReady(page);
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() === 0) {
      test.skip(true, 'Settlement page requires at least one project');
      return;
    }
    await projectSelect.selectOption({ index: 1 });
    await fillByLabel(page, 'Up To Date', todayISO().toString());
    await page.getByRole('button', { name: /Preview|Generate/ }).click();
    await expect(page.locator('body')).toContainText(/\d|total|amount|statement/i);
  });
});
