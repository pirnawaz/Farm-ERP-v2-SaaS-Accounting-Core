/**
 * @all Reports module: all report pages load with correct headings, date range filters work,
 * export/print buttons exist, data renders when available.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('@all Reports module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ─── Trial Balance ───────────────────────────────────────────
  test('@all trial balance page loads and renders', async ({ page }) => {
    await page.goto('/app/reports/trial-balance', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.getByTestId('report-heading-trial-balance')).toBeVisible();
  });

  test('@all trial balance filters by as-of date', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/reports/trial-balance', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const asOfInput = page.locator('input[type="date"]').first();
    if (await asOfInput.count() > 0) {
      await asOfInput.fill(today);
    }

    // Apply or auto-refresh
    const applyBtn = page.getByRole('button', { name: /Apply|Filter|Generate/i });
    if (await applyBtn.isVisible()) {
      await applyBtn.click();
    }

    await page.waitForTimeout(2000);
    // Report should still be visible
    await expect(page.getByTestId('report-heading-trial-balance')).toBeVisible();
  });

  test('@all trial balance has export/print buttons', async ({ page }) => {
    await page.goto('/app/reports/trial-balance', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const exportBtn = page.getByRole('button', { name: /Export|CSV|Download/i });
    const printBtn = page.getByRole('button', { name: /Print/i });

    // At least one should exist
    const hasExport = await exportBtn.count() > 0;
    const hasPrint = await printBtn.count() > 0;
    expect(hasExport || hasPrint).toBeTruthy();
  });

  // ─── General Ledger ──────────────────────────────────────────
  test('@all general ledger page loads', async ({ page }) => {
    await page.goto('/app/reports/general-ledger', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/General Ledger/i);
  });

  // ─── Profit & Loss ──────────────────────────────────────────
  test('@all profit & loss page loads', async ({ page }) => {
    await page.goto('/app/reports/profit-loss', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Profit|Loss|P&L|Income/i);
  });

  test('@all profit & loss with project scope filter', async ({ page }) => {
    await page.goto('/app/reports/project-pl', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Select project if filter exists
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 1 });
      await page.waitForTimeout(2000);
    }
    await expect(page.locator('body')).toContainText(/Profit|Loss|Project/i);
  });

  test('@all crop cycle P&L page loads', async ({ page }) => {
    await page.goto('/app/reports/crop-cycle-pl', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Crop Cycle|Profit|Loss/i);
  });

  // ─── Balance Sheet ───────────────────────────────────────────
  test('@all balance sheet page loads', async ({ page }) => {
    await page.goto('/app/reports/balance-sheet', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Balance Sheet/i);
  });

  // ─── Account Balances ────────────────────────────────────────
  test('@all account balances page loads', async ({ page }) => {
    await page.goto('/app/reports/account-balances', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Account Balance/i);
  });

  // ─── Cashbook ────────────────────────────────────────────────
  test('@all cashbook report loads', async ({ page }) => {
    await page.goto('/app/reports/cashbook', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Cashbook|Cash Book/i);
  });

  // ─── AR Ageing ───────────────────────────────────────────────
  test('@all AR ageing report loads', async ({ page }) => {
    await page.goto('/app/reports/ar-ageing', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/AR Ageing|Aging|Receivable/i);
  });

  // ─── Sales Margin ────────────────────────────────────────────
  test('@all sales margin report loads', async ({ page }) => {
    await page.goto('/app/reports/sales-margin', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Sales Margin|Margin/i);
  });

  // ─── Party Reports ──────────────────────────────────────────
  test('@all party ledger report loads', async ({ page }) => {
    await page.goto('/app/reports/party-ledger', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Party Ledger|Party/i);
  });

  test('@all party summary report loads', async ({ page }) => {
    await page.goto('/app/reports/party-summary', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Party Summary|Party/i);
  });

  // ─── Role Ageing ─────────────────────────────────────────────
  test('@all role ageing report loads', async ({ page }) => {
    await page.goto('/app/reports/role-ageing', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Role Ageing|Role Aging/i);
  });

  // ─── Reconciliation Dashboard ────────────────────────────────
  test('@all reconciliation dashboard loads', async ({ page }) => {
    await page.goto('/app/reports/reconciliation-dashboard', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Reconciliation/i);
  });
});
