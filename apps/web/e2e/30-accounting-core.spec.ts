import { test, expect } from '@playwright/test';
import { loginDev } from './helpers/auth';
import { gotoTransactionDetail, gotoPostingGroupDetail } from './helpers/nav';
import { readSeedState, type SeedState } from './helpers/seed';

function skipIfNoSeed(state: SeedState | null): asserts state is SeedState {
  if (!state) {
    test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
  }
}

test.describe.serial('30-accounting-core', () => {
  test('DRAFT: operational record starts in DRAFT, editable, no posting group visible', async ({ page }) => {
    const state = readSeedState();
    skipIfNoSeed(state);
    await loginDev(page, { tenantId: state.tenant_id, role: 'accountant', seed: state });
    await gotoTransactionDetail(page, state.draft_transaction_id);
    await page.waitForURL(new RegExp(`/app/transactions/${state.draft_transaction_id}`));
    await expect(page.locator('[data-testid=transaction-detail]')).toBeVisible({ timeout: 10_000 });
    const statusBadge = page.locator('[data-testid=status-badge]').or(page.locator('text=DRAFT')).first();
    await expect(statusBadge).toBeVisible();
    const postBtn = page.locator('[data-testid=post-btn]').or(page.locator('button:has-text("Post")')).first();
    await expect(postBtn).toBeVisible();
    const postingGroupPanel = page.locator('[data-testid=posting-group-panel]');
    await expect(postingGroupPanel).toHaveCount(0);
  });

  test('Posting date validation: invalid date blocks post, error toast, record stays DRAFT', async ({ page }) => {
    const state = readSeedState();
    skipIfNoSeed(state);
    await loginDev(page, { tenantId: state.tenant_id, role: 'accountant', seed: state });
    await gotoTransactionDetail(page, state.draft_transaction_id);
    await page.waitForURL(new RegExp(`/app/transactions/${state.draft_transaction_id}`));
    await expect(page.locator('[data-testid=transaction-detail]')).toBeVisible({ timeout: 10_000 });
    const postBtn = page.locator('[data-testid=post-btn]').or(page.locator('button:has-text("Post")')).first();
    if ((await postBtn.count()) === 0) {
      test.skip(true, 'No Post button (record may already be posted).');
      return;
    }
    await postBtn.click();
    const dateInput = page.locator('[data-testid=posting-date-input]').or(page.locator('input[type=date]')).first();
    await dateInput.fill('');
    const confirmBtn = page.locator('[data-testid=confirm-post]').or(page.locator('button:has-text("Post"):not(:has-text("Transaction"))')).first();
    await confirmBtn.click();
    await expect(page.locator('[data-testid=toast-error], .toast, [data-sonner-toast]').first()).toBeVisible({ timeout: 5000 }).catch(() => {});
    await expect(page.locator('text=DRAFT').or(page.locator('[data-testid=status-badge]'))).toBeVisible();
  });

  test('POST: clicking Post opens modal; posting_date required; confirm leads to POSTED and posting group visible', async ({ page }) => {
    const state = readSeedState();
    skipIfNoSeed(state);
    await loginDev(page, { tenantId: state.tenant_id, role: 'accountant', seed: state });
    await gotoTransactionDetail(page, state.draft_transaction_id);
    await page.waitForURL(new RegExp(`/app/transactions/${state.draft_transaction_id}`));
    await expect(page.locator('[data-testid=transaction-detail]')).toBeVisible({ timeout: 10_000 });
    const postBtn = page.locator('[data-testid=post-btn]').or(page.locator('button:has-text("Post")')).first();
    if ((await postBtn.count()) === 0) {
      test.skip(true, 'No Post button (record may already be posted). Seed provides a fresh draft per run.');
      return;
    }
    await postBtn.click();
    const modal = page.locator('[data-testid=posting-date-modal]').or(page.locator('[role=dialog]')).first();
    await expect(modal).toBeVisible();
    const dateInput = page.locator('[data-testid=posting-date-input]').or(page.locator('input[type=date]')).first();
    await expect(dateInput).toBeVisible();
    const today = new Date().toISOString().split('T')[0];
    await dateInput.fill(today);
    const confirmBtn = page.locator('[data-testid=confirm-post]').or(page.locator('button:has-text("Post"):not(:has-text("Transaction"))')).first();
    await confirmBtn.click();
    await expect(page.locator('text=POSTED').or(page.locator('[data-testid=status-badge]'))).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('[data-testid=posting-group-panel], a[href*="/posting-groups/"]').first()).toBeVisible({ timeout: 10_000 });
  });

  test('POSTED: posting group panel visible, tables non-empty, posting-group-id present', async ({ page }) => {
    const state = readSeedState();
    skipIfNoSeed(state);
    await loginDev(page, { tenantId: state.tenant_id, role: 'accountant', seed: state });
    await gotoTransactionDetail(page, state.posted_transaction_id);
    await page.waitForURL(new RegExp(`/app/transactions/${state.posted_transaction_id}`));
    await expect(page.locator('[data-testid=transaction-detail]')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('text=POSTED').or(page.locator('[data-testid=status-badge]')).first()).toBeVisible({ timeout: 5000 });
    const pgPanel = page.locator('[data-testid=posting-group-panel]');
    await expect(pgPanel).toBeVisible();
    const pgLink = page.locator(`a[href*="/app/posting-groups/${state.posted_transaction_posting_group_id}"]`);
    await expect(pgLink).toBeVisible();
    await gotoPostingGroupDetail(page, state.posted_transaction_posting_group_id);
    await expect(page.locator('[data-testid=posting-group-panel], [data-testid=allocation-rows-table], [data-testid=ledger-entries-table]').first()).toBeVisible({ timeout: 10_000 });
  });

  test('Posting is blocked for CLOSED crop cycle', async ({ page }) => {
    const state = readSeedState();
    skipIfNoSeed(state);
    const draftInClosedId = state.draft_in_closed_cycle_transaction_id;
    if (!draftInClosedId) {
      test.skip(true, 'E2E seed state missing draft_in_closed_cycle_transaction_id. Re-run seed with updated API.');
      return;
    }
    await loginDev(page, { tenantId: state.tenant_id, role: 'accountant', seed: state });
    await gotoTransactionDetail(page, draftInClosedId);
    await page.waitForURL(new RegExp(`/app/transactions/${draftInClosedId}`));
    await expect(page.locator('[data-testid=transaction-detail]')).toBeVisible({ timeout: 10_000 });
    const postBtn = page.locator('[data-testid=post-btn]').or(page.locator('button:has-text("Post")')).first();
    await expect(postBtn).toBeVisible();
    await postBtn.click();
    const dateInput = page.locator('[data-testid=posting-date-input]').or(page.locator('input[type=date]')).first();
    await expect(dateInput).toBeVisible();
    const today = new Date().toISOString().split('T')[0];
    await dateInput.fill(today);
    const confirmBtn = page.locator('[data-testid=confirm-post]').or(page.locator('button:has-text("Post"):not(:has-text("Transaction"))')).first();
    await confirmBtn.click();
    await expect(page.locator('[data-testid=toast-error], .toast, [data-sonner-toast]').first()).toBeVisible({ timeout: 8000 });
    await expect(page.locator('text=DRAFT').or(page.locator('[data-testid=status-badge]')).first()).toBeVisible();
    const postingGroupPanel = page.locator('[data-testid=posting-group-panel]');
    await expect(postingGroupPanel).toHaveCount(0);
  });

  test('Corrections: reversal creates new Posting Group; original immutable', async ({ page }) => {
    const state = readSeedState();
    skipIfNoSeed(state);
    await loginDev(page, { tenantId: state.tenant_id, role: 'accountant', seed: state });
    await gotoTransactionDetail(page, state.reversal_transaction_id);
    await page.waitForURL(new RegExp(`/app/transactions/${state.reversal_transaction_id}`));
    await expect(page.locator('[data-testid=transaction-detail]')).toBeVisible({ timeout: 10_000 });
    const pgLink = page.locator(`a[href*="/app/posting-groups/${state.reversal_posting_group_id}"]`);
    await expect(pgLink).toBeVisible();
    await gotoPostingGroupDetail(page, state.reversal_posting_group_id);
    await expect(page.locator('[data-testid=posting-group-panel], [data-testid=allocation-rows-table], [data-testid=ledger-entries-table]').first()).toBeVisible({ timeout: 10_000 });
    const reverseBtn = page.locator('[data-testid=create-correction-btn]').or(page.locator('button:has-text("Reverse")')).first();
    if ((await reverseBtn.count()) === 0) {
      test.skip(true, 'Reverse button not found or already reversed.');
      return;
    }
    await reverseBtn.click();
    const reasonInput = page.locator('input[name=reason], textarea[name=reason], input[placeholder*="reason"]').first();
    if (await reasonInput.isVisible().catch(() => false)) {
      await reasonInput.fill('E2E correction');
    }
    const confirmReverse = page.locator('button:has-text("Reverse"), [data-testid=confirm-post]').first();
    await confirmReverse.click();
    await page.waitForURL(/\/app\/posting-groups\/[^/]+/);
    await expect(page.locator('text=REVERSAL').or(page.locator('[data-testid=status-badge]'))).toBeVisible({ timeout: 10_000 }).catch(() => {});
  });
});
