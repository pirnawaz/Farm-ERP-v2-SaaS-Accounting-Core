/**
 * Deterministic E2E seed: call POST /api/dev/e2e/seed and write .seed-state.json.
 * Requires API running with APP_DEBUG=true (dev routes return 403 otherwise).
 * If the seed call fails (403 / API down), we do not write the file; tests that
 * depend on seed must skip with a clear message (see helpers/seed.ts).
 */
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SEED_STATE_PATH = path.resolve(__dirname, '.seed-state.json');

export interface SeedState {
  tenant_id: string;
  crop_cycle_id: string;
  open_crop_cycle_id?: string;
  closed_crop_cycle_id?: string;
  project_id: string;
  draft_transaction_id: string;
  posted_transaction_id: string;
  posted_transaction_posting_group_id: string;
  reversal_transaction_id: string;
  reversal_posting_group_id: string;
  draft_in_closed_cycle_transaction_id?: string;
  draft_machinery_service_id: string | null;
  posted_machinery_service_id: string | null;
  posted_machinery_posting_group_id: string | null;
  /** Present after re-seed; required for E2E cookie auth */
  tenant_admin_user_id?: string;
  accountant_user_id?: string;
  operator_user_id?: string;
  platform_admin_user_id?: string;
}

async function globalSetup(): Promise<void> {
  const apiUrl = process.env.API_URL || 'http://localhost:8000';
  const defaultTenantId = process.env.DEFAULT_TENANT_ID || undefined;
  const tenantName = 'E2E Farm';

  try {
    const res = await fetch(`${apiUrl}/api/dev/e2e/seed`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        tenant_id: defaultTenantId ?? undefined,
        tenant_name: tenantName,
      }),
    });

    if (!res.ok) {
      const text = await res.text();
      console.warn('[E2E globalSetup] POST /api/dev/e2e/seed failed:', res.status, text);
      console.warn('[E2E globalSetup] Ensure API is running with APP_DEBUG=true. Tests that need seed will skip.');
      return;
    }

    const state = (await res.json()) as SeedState;
    fs.writeFileSync(SEED_STATE_PATH, JSON.stringify(state, null, 2), 'utf-8');
    console.log('[E2E globalSetup] Seed OK tenant=' + state.tenant_id);
  } catch (err) {
    console.warn('[E2E globalSetup] Error calling seed:', err);
    console.warn('[E2E globalSetup] Ensure API is reachable at', apiUrl, '. Tests that need seed will skip.');
  }
}

export default globalSetup;
