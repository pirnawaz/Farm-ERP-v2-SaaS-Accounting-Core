/**
 * Read or require E2E seed state from .seed-state.json (written by globalSetup).
 */
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SEED_STATE_PATH = path.resolve(__dirname, '..', '.seed-state.json');

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
  tenant_admin_user_id?: string;
  accountant_user_id?: string;
  operator_user_id?: string;
  platform_admin_user_id?: string;
  draft_machinery_service_id: string | null;
  posted_machinery_service_id: string | null;
  posted_machinery_posting_group_id: string | null;
}

/**
 * Returns parsed seed state or null if file is missing/invalid.
 */
export function readSeedState(): SeedState | null {
  try {
    if (!fs.existsSync(SEED_STATE_PATH)) {
      return null;
    }
    const raw = fs.readFileSync(SEED_STATE_PATH, 'utf-8');
    const data = JSON.parse(raw) as SeedState;
    if (!data.tenant_id || !data.draft_transaction_id) {
      return null;
    }
    return data;
  } catch {
    return null;
  }
}

/**
 * Returns seed state or throws with a message suitable for test.skip().
 */
export function requireSeedState(): SeedState {
  const state = readSeedState();
  if (state) {
    return state;
  }
  throw new Error(
    'E2E seed state missing. Run with API at API_URL and APP_DEBUG=true so globalSetup can call POST /api/dev/e2e/seed.'
  );
}
