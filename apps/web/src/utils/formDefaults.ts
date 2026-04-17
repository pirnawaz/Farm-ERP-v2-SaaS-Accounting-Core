/**
 * Client-side form defaults for mobile-first data entry.
 * - Auto doc_no generation when required
 * - Active (OPEN) crop cycle default
 * - Remember last selections in localStorage (key prefix: farm_erp_form_)
 */

const STORAGE_PREFIX = 'farm_erp_form_';

export function generateDocNo(prefix: string): string {
  const date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
  const time = String(Date.now()).slice(-6);
  return `${prefix}-${date}-${time}`;
}

export type CropCycleLike = { id: string; status?: string };

/** First OPEN crop cycle, or first in list if none have status. */
export function getActiveCropCycleId(cycles: CropCycleLike[] | undefined): string | undefined {
  if (!cycles?.length) return undefined;
  const open = cycles.find((c) => (c as { status?: string }).status === 'OPEN');
  return open?.id ?? cycles[0]?.id;
}

export function getStored<T = string>(key: string): T | null {
  try {
    const raw = localStorage.getItem(STORAGE_PREFIX + key);
    if (raw == null) return null;
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

export function setStored(key: string, value: unknown): void {
  try {
    localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(value));
  } catch {
    // ignore
  }
}

export const formStorageKeys = {
  last_land_parcel_id: 'last_land_parcel_id',
  last_crop_cycle_id: 'last_crop_cycle_id',
  last_project_id: 'last_project_id',
  last_store_id: 'last_store_id',
  last_worker_id: 'last_worker_id',
  last_labour_rate: 'last_labour_rate',
  last_supplier_party_id: 'last_supplier_party_id',
  last_production_unit_id: 'last_production_unit_id',
  /** Last selections on machinery usage (work entry) form */
  last_machinery_machine_id: 'last_machinery_machine_id',
  last_machinery_project_id: 'last_machinery_project_id',
  last_transfer_from_store_id: 'last_transfer_from_store_id',
  last_transfer_to_store_id: 'last_transfer_to_store_id',
} as const;

const LAST_SUBMIT_PREFIX = 'farm_erp_last_submit_';

function lastSubmitKey(tenantId: string, formId: string): string {
  return LAST_SUBMIT_PREFIX + tenantId + '_' + formId;
}

/** Store last successful submission payload (sanitized) for "Copy last" in forms. */
export function setLastSubmit(tenantId: string, formId: string, payload: unknown): void {
  try {
    localStorage.setItem(lastSubmitKey(tenantId, formId), JSON.stringify(payload));
  } catch {
    // ignore
  }
}

/** Retrieve last successful submission for "Copy last". */
export function getLastSubmit<T = unknown>(tenantId: string, formId: string): T | null {
  try {
    const raw = localStorage.getItem(lastSubmitKey(tenantId, formId));
    if (raw == null) return null;
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}
