/**
 * @all Land Leases (Maqada) module: CRUD, accrual lifecycle (draft → post → reverse).
 * Land lease accruals use inline Post/Reverse action buttons in the accrual table,
 * which open Modal dialogs with Posting Date and optional Reason fields.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO, addDaysISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

const runId = `LL-${Date.now()}`;

test.describe('@all Land Leases module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  test('@all land leases list page loads', async ({ page }) => {
    await page.goto('/app/land-leases', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page).toHaveURL(/\/app\/land-leases/);
    await expect(page.getByTestId('land-leases-page')).toBeVisible();
  });

  test('@all create a new land lease', async ({ page }) => {
    const today = todayISO();
    const endDate = addDaysISO(today, 365);

    await page.goto('/app/land-leases', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await page.getByTestId('new-land-lease').click();

    // LandLeasesPage modal fields: Project, Land Parcel, Landlord, Start date, End date, Rent amount, Frequency, Notes
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 1 });
    }
    const parcelSelect = page.locator('label:has-text("Land Parcel")').locator('..').locator('select').first();
    if (await parcelSelect.count() > 0) {
      await parcelSelect.selectOption({ index: 1 });
    }
    await fillByLabel(page, 'Landlord', `Landlord-${runId}`);
    await fillByLabel(page, 'Start date', today);
    await fillByLabel(page, 'End date', endDate);
    await fillByLabel(page, 'Rent amount', '12000');

    await page.locator('[role="dialog"]').getByRole('button', { name: /Create|Save/i }).click();
    await expect(
      page.getByText(/created successfully|success/i)
    ).toBeVisible({ timeout: 8000 });
  });

  test('@all navigate to land lease detail page', async ({ page }) => {
    await page.goto('/app/land-leases', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const row = page.locator('table tbody tr').first();
    if (await row.count() === 0) {
      test.skip(true, 'No land leases available');
      return;
    }

    await row.click();
    await expect(page).toHaveURL(/\/app\/land-leases\/[0-9a-f-]+/);
    await expect(page.getByTestId('land-lease-detail-page')).toBeVisible();
  });

  test('@all create accrual on land lease (draft)', async ({ page }) => {
    await page.goto('/app/land-leases', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const row = page.locator('table tbody tr').first();
    if (await row.count() === 0) {
      test.skip(true, 'No land leases available for accrual creation');
      return;
    }

    await row.click();
    await expect(page.getByTestId('land-lease-detail-page')).toBeVisible();
    await page.getByTestId('new-accrual').click();

    // Accrual form fields: Period start, Period end, Amount, Memo
    await fillByLabel(page, 'Period start', todayISO());
    await fillByLabel(page, 'Period end', addDaysISO(todayISO(), 30));
    await fillByLabel(page, 'Amount', '1000');

    await page.locator('[role="dialog"]').getByRole('button', { name: /Create|Save/i }).click();
    await expect(
      page.getByText(/created|success/i)
    ).toBeVisible({ timeout: 8000 });
  });

  test('@all post accrual via inline action button', async ({ page }) => {
    const today = todayISO();

    await page.goto('/app/land-leases', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const row = page.locator('table tbody tr').first();
    if (await row.count() === 0) {
      test.skip(true, 'No land leases available');
      return;
    }

    await row.click();
    await expect(page.getByTestId('land-lease-detail-page')).toBeVisible();

    // Accrual table has inline action buttons per row: Post (for DRAFT), Reverse (for POSTED)
    const draftRow = page.locator('table tbody tr').filter({ hasText: /DRAFT/i }).first();
    if (await draftRow.count() === 0) {
      test.skip(true, 'No draft accruals available to post');
      return;
    }

    // Click the Post button in the accrual row
    const postBtn = draftRow.getByRole('button', { name: /Post/i });
    if (!(await postBtn.isVisible())) {
      test.skip(true, 'Post button not visible on draft accrual');
      return;
    }
    await postBtn.click();

    // Post accrual modal: Posting Date field + warning about accounting entries
    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting date', today);
    await dialog.getByRole('button', { name: /Post|Confirm/i }).click();
    await expect(page.getByText(/posted|success/i)).toBeVisible({ timeout: 10000 });
  });

  test('@all reverse posted accrual via inline action button', async ({ page }) => {
    const today = todayISO();

    await page.goto('/app/land-leases', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const row = page.locator('table tbody tr').first();
    if (await row.count() === 0) {
      test.skip(true, 'No land leases available');
      return;
    }

    await row.click();
    await expect(page.getByTestId('land-lease-detail-page')).toBeVisible();

    // Find a POSTED accrual row with Reverse button
    const postedRow = page.locator('table tbody tr').filter({ hasText: /POSTED/i }).first();
    if (await postedRow.count() === 0) {
      test.skip(true, 'No posted accruals available to reverse');
      return;
    }

    const reverseBtn = postedRow.getByRole('button', { name: /Reverse/i });
    if (!(await reverseBtn.isVisible())) {
      test.skip(true, 'Reverse button not visible on posted accrual');
      return;
    }
    await reverseBtn.click();

    // Reverse accrual modal: Posting Date + Reason fields
    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();
    await fillByLabel(page, 'Posting date', today);
    await dialog.getByRole('button', { name: /Reverse|Confirm/i }).click();
    await expect(page.getByText(/reversed|success/i)).toBeVisible({ timeout: 10000 });
  });
});
