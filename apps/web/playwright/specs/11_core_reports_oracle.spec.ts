/**
 * Core correctness verification: compare ledger-derived oracle to report API.
 * Fetches ledger entries for a posting group, aggregates in oracle, fetches trial balance,
 * asserts report balances and that report matches oracle for touched accounts.
 */

import { test, expect } from '@playwright/test';
import {
  aggregateLedgerEntries,
  assertLedgerBalanced,
  assertTrialBalanceBalanced,
  assertReportMatchesOracle,
  type LedgerEntryLike,
  type TrialBalanceRowLike,
} from '../helpers/oracle';
import { todayISO } from '../helpers/dates';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { waitForModulesReady } from '../helpers/readiness';
import { getSeed } from '../helpers/seed';

const API_BASE_URL = process.env.API_BASE_URL ?? 'http://localhost:8000';

function apiHeaders(tenantId: string, userId: string): Record<string, string> {
  return {
    'X-Tenant-Id': tenantId,
    'X-User-Id': userId,
    'X-User-Role': 'tenant_admin',
    Accept: 'application/json',
  };
}

test.describe('@core Core reports oracle', () => {
  test('@core ledger entries balance and trial balance matches oracle for posting', async ({
    page,
    request,
  }) => {
    const seed = getSeed<{ tenant_id: string; tenant_admin_user_id: string }>();
    const tenantId = seed.tenant_id;
    const headers = apiHeaders(tenantId, seed.tenant_admin_user_id);
    const today = todayISO();
    const runId = `Oracle-${Date.now()}`;

    // Create a minimal chain and post a transaction (same flow as core journey but minimal)
    await page.goto('/app/land', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByTestId('app-sidebar')).toBeVisible();
    await expect(page.getByTestId('land-parcels-page')).toBeVisible();
    await page.getByTestId('new-land-parcel').click();
    await fillByLabel(page, 'Name', runId);
    await fillByLabel(page, 'Total Acres', '5');
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.locator('[role="dialog"]')).toHaveCount(0, { timeout: 15000 });
    await expect(page.getByTestId('land-parcels-page').getByText(runId)).toBeVisible({ timeout: 15000 });
    await page.goto('/app/crop-cycles');
    await waitForModulesReady(page);
    await expect(page.getByTestId('app-sidebar')).toBeVisible();
    await page.getByTestId('new-crop-cycle').click();
    await fillByLabel(page, 'Name', `C-${runId}`);
    await fillByLabel(page, 'Start Date', today);
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.locator('[role="dialog"]')).toHaveCount(0, { timeout: 15000 });
    await expect(page.getByTestId('crop-cycles-page').getByText(`C-${runId}`)).toBeVisible({ timeout: 15000 });
    await page.goto('/app/allocations');
    await page.getByTestId('new-land-allocation').click();
    await selectByLabelOption(page, 'Crop Cycle', new RegExp(`C-${runId}`));
    await selectByLabelOption(page, 'Land Parcel', runId);
    await page.getByLabel('Owner-operated', { exact: false }).check();
    await fillByLabel(page, 'Allocated Acres', '5');
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText('Land allocation created successfully')).toBeVisible({ timeout: 5000 });
    await page.goto('/app/projects');
    await page.getByRole('button', { name: 'New Project from Allocation' }).click();
    await selectByLabelOption(page, 'Allocation', new RegExp(runId));
    await fillByLabel(page, 'Project Name', `P-${runId}`);
    await page.locator('[role="dialog"]').getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText('Project created successfully').or(page.getByText('Your first project'))).toBeVisible({ timeout: 5000 });
    await page.goto('/app/transactions/new');
    await selectByLabelOption(page, 'Destination', 'Project');
    await selectByLabelOption(page, 'Project', `P-${runId}`);
    await selectByLabelOption(page, 'Classification', 'Shared');
    await fillByLabel(page, 'Transaction Date', today);
    await fillByLabel(page, 'Amount', '200');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page).toHaveURL(/\/app\/transactions/);
    await page.goto('/app/transactions');
    await page.locator('table tbody tr').filter({ hasText: '200' }).first().click();
    await page.getByTestId('post-btn').click();
    await page.getByTestId('posting-date-input').fill(today);
    await page.getByTestId('confirm-post').click();
    await expect(page.locator('[data-testid="status-badge"]')).toContainText('POSTED', { timeout: 10000 });
    const pgLink = page.getByTestId('posting-group-id');
    await expect(pgLink).toBeVisible();
    const href = await pgLink.getAttribute('href');
    const postingGroupId = href?.split('/').pop() ?? null;
    expect(postingGroupId).toBeTruthy();

    // Fetch ledger entries from API (same as UI)
    const ledgerRes = await request.get(
      `${API_BASE_URL}/api/posting-groups/${postingGroupId}/ledger-entries`,
      { headers }
    );
    expect(ledgerRes.ok()).toBeTruthy();
    const ledgerEntries = (await ledgerRes.json()) as LedgerEntryLike[];

    // Oracle: assert ledger balances
    assertLedgerBalanced(ledgerEntries);
    const { totalDebits, totalCredits, perAccountNet } = aggregateLedgerEntries(ledgerEntries);
    expect(Math.abs(totalDebits - totalCredits)).toBeLessThan(0.01);

    // Fetch trial balance for same date range
    const tbRes = await request.get(
      `${API_BASE_URL}/api/reports/trial-balance?from=${today}&to=${today}`,
      { headers }
    );
    expect(tbRes.ok()).toBeTruthy();
    const trialBalance = (await tbRes.json()) as TrialBalanceRowLike[];

    // Report must balance
    assertTrialBalanceBalanced(trialBalance);

    // For accounts touched by this posting group, report net should match oracle
    assertReportMatchesOracle(trialBalance, perAccountNet, true);
  });
});
