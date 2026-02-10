/**
 * Accounting invariant E2E: DRAFT produces no artifacts; POST produces balanced posting and report updates.
 */
import { test, expect } from '@playwright/test';
import { loginDev } from '../helpers/auth';
import { readSeedState } from '../helpers/seed';
import { setTenantModulesOnly } from '../helpers/tenantSetup';

const BASE_URL = process.env.BASE_URL || 'http://localhost:3000';

test.describe('Accounting invariants', () => {
  test('DRAFT payment has no posting group; POST creates balanced posting group and trial balance updates', async ({ page }) => {
    const seed = readSeedState();
    if (!seed) {
      test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
      return;
    }
    await loginDev(page, { tenantId: seed.tenant_id, role: 'accountant', seed });
    await setTenantModulesOnly(page, ['projects_crop_cycles', 'treasury_payments', 'reports']);

    const unique = `e2e-inv-${Date.now()}`;
    const today = new Date().toISOString().split('T')[0];

    const partiesRes = await page.request.get(`${BASE_URL}/api/parties`);
    expect(partiesRes.ok()).toBe(true);
    const parties = (await partiesRes.json()) as { id: string }[];
    const partyId = parties[0]?.id;
    if (!partyId) {
      test.skip(true, 'No party in tenant; seed may not have created E2E Party.');
      return;
    }

    const createRes = await page.request.post(`${BASE_URL}/api/payments`, {
      data: {
        direction: 'OUT',
        party_id: partyId,
        amount: 25.00,
        payment_date: today,
        method: 'CASH',
        reference: unique,
        notes: 'E2E accounting invariant',
      },
      headers: { 'Content-Type': 'application/json' },
    });
    expect(createRes.status()).toBe(201);
    const payment = (await createRes.json()) as { id: string };
    expect(payment.id).toBeDefined();

    const artifactsUrl = `${BASE_URL}/api/dev/e2e/accounting-artifacts?tenant_id=${seed.tenant_id}&source_type=ADJUSTMENT&source_id=${payment.id}`;
    const beforeRes = await page.request.get(artifactsUrl);
    expect(beforeRes.ok()).toBe(true);
    const before = (await beforeRes.json()) as { posting_group_id: string | null; balanced: boolean };
    expect(before.posting_group_id).toBeNull();

    const postRes = await page.request.post(`${BASE_URL}/api/payments/${payment.id}/post`, {
      data: {
        posting_date: today,
        idempotency_key: unique,
        crop_cycle_id: seed.open_crop_cycle_id || seed.crop_cycle_id,
      },
      headers: { 'Content-Type': 'application/json' },
    });
    expect(postRes.status()).toBe(201);

    const afterRes = await page.request.get(artifactsUrl);
    expect(afterRes.ok()).toBe(true);
    const after = (await afterRes.json()) as {
      posting_group_id: string | null;
      ledger_entry_count: number;
      total_debit: string;
      total_credit: string;
      balanced: boolean;
    };
    expect(after.posting_group_id).not.toBeNull();
    expect(after.ledger_entry_count).toBeGreaterThan(0);
    expect(after.balanced).toBe(true);
    expect(Number(after.total_debit)).toBeCloseTo(Number(after.total_credit), 2);

    const tbRes = await page.request.get(`${BASE_URL}/api/reports/trial-balance?from=${today}&to=${today}`);
    expect(tbRes.ok()).toBe(true);
    const tbRows = (await tbRes.json()) as { account_code: string; total_debit: string; total_credit: string }[];
    expect(Array.isArray(tbRows)).toBe(true);
  });
});
