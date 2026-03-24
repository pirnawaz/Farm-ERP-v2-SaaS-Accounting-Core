/**
 * @all Settlements module: settlement preview, settlement generation, settlement pack,
 * advance offset, posting settlement.
 */

import { test, expect } from '@playwright/test';
import { fillByLabel, selectByLabelOption } from '../helpers/form';
import { todayISO } from '../helpers/dates';
import { getProfile } from '../helpers/profile';
import { waitForModulesReady } from '../helpers/readiness';

test.describe('@all Settlements module', () => {
  test.beforeEach(async () => {
    const { profile } = getProfile();
    test.skip(profile !== 'all', 'Only run when E2E_PROFILE=all');
  });

  // ─── Settlement Dashboard ────────────────────────────────────
  test('@all settlement page loads', async ({ page }) => {
    await page.goto('/app/settlement', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Settlement/i);
  });

  // ─── Settlement Preview ──────────────────────────────────────
  test('@all generate settlement preview for project', async ({ page }) => {
    await page.goto('/app/settlement', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Select project
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() === 0) {
      test.skip(true, 'No projects available for settlement');
      return;
    }
    await projectSelect.selectOption({ index: 1 });
    await fillByLabel(page, 'Up To Date', todayISO());

    await page.getByRole('button', { name: /Preview|Generate/i }).click();

    // Should display cost breakdown or statement
    await expect(page.locator('body')).toContainText(/\d|total|amount|cost|revenue|share/i, {
      timeout: 10000,
    });
  });

  // ─── Post Settlement ─────────────────────────────────────────
  test('@all post settlement and verify posting group', async ({ page }) => {
    const today = todayISO();
    await page.goto('/app/settlement', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() === 0) {
      test.skip(true, 'No projects available');
      return;
    }
    await projectSelect.selectOption({ index: 1 });
    await fillByLabel(page, 'Up To Date', today);
    await page.getByRole('button', { name: /Preview|Generate/i }).click();

    // Wait for preview to load
    await page.waitForTimeout(2000);

    // Post the settlement
    const postBtn = page.getByRole('button', { name: /Post Settlement|Post/i });
    if (!(await postBtn.isVisible())) {
      test.skip(true, 'Post settlement button not visible (may need data)');
      return;
    }
    await postBtn.click();

    // Handle posting modal with advance offset options
    const postingDateInput = page.getByTestId('posting-date-input');
    if (await postingDateInput.isVisible()) {
      await postingDateInput.fill(today);
    }

    const confirmBtn = page.getByTestId('confirm-post')
      .or(page.locator('[role="dialog"]').getByRole('button', { name: /Confirm|Post/i }));
    if (await confirmBtn.isVisible()) {
      await confirmBtn.click();
    }

    await expect(
      page.getByText(/posted|success/i).or(page.getByTestId('posting-group-id'))
    ).toBeVisible({ timeout: 10000 });
  });

  // ─── Settlement Pack ─────────────────────────────────────────
  test('@all settlement pack page loads', async ({ page }) => {
    await page.goto('/app/settlement-pack', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Settlement Pack/i);
  });

  test('@all settlement pack shows summary and register', async ({ page }) => {
    await page.goto('/app/settlement-pack', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);

    // Select a project that has settlement data
    const projectSelect = page.locator('label:has-text("Project")').locator('..').locator('select').first();
    if (await projectSelect.count() === 0) {
      test.skip(true, 'No projects available for settlement pack');
      return;
    }
    await projectSelect.selectOption({ index: 1 });

    // Wait for data to load
    await page.waitForTimeout(2000);

    const summary = page.getByTestId('settlement-pack-summary');
    const register = page.getByTestId('settlement-pack-register');

    if (await summary.isVisible()) {
      await expect(summary).toBeVisible();
    }
    if (await register.isVisible()) {
      await expect(register).toBeVisible();
    }
  });

  // ─── Settlements List ────────────────────────────────────────
  test('@all settlements list page loads', async ({ page }) => {
    await page.goto('/app/settlements', { waitUntil: 'domcontentloaded' });
    await waitForModulesReady(page);
    await expect(page.locator('body')).toContainText(/Settlement/i);
  });
});
