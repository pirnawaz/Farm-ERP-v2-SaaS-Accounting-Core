import { apiClient } from '@farm-erp/shared';
import type {
  Harvest,
  HarvestLine,
  CreateHarvestPayload,
  UpdateHarvestPayload,
  PostHarvestPayload,
  ReverseHarvestPayload,
  HarvestShareLinePayload,
  HarvestSharePreviewResponse,
  HarvestRecipientRole,
  HarvestSettlementMode,
  HarvestShareBasis,
} from '../types';

/** Extra fields accepted by POST share-lines (traceability); aligned with API. */
export type HarvestShareLineCreatePayload = HarvestShareLinePayload & {
  source_field_job_id?: string | null;
};

export type HarvestSuggestionConfidence = 'HIGH' | 'MEDIUM' | 'LOW';

export interface HarvestMachineSuggestionRow {
  field_job_id?: string | null;
  field_job_machine_id?: string | null;
  machine_id: string;
  machine_code?: string | null;
  machine_name?: string | null;
  usage_qty?: string | null;
  meter_unit_snapshot?: string | null;
  pool_total_usage: string;
  suggested_recipient_role: string;
  suggested_settlement_mode: string;
  suggested_share_basis: string;
  /** Present when basis is PERCENT or FIXED_QTY */
  suggested_share_value?: string | null;
  suggested_ratio_numerator?: string | null;
  suggested_ratio_denominator?: string | null;
  suggested_source_field_job_id?: string | null;
  suggestion_source?: 'AGREEMENT' | 'FIELD_JOB' | string;
  agreement_id?: string | null;
}

export interface HarvestLabourSuggestionRow {
  field_job_id?: string | null;
  field_job_labour_id?: string | null;
  worker_id: string;
  worker_name?: string | null;
  units?: string | null;
  rate_basis?: string | null;
  pool_total_units: string;
  suggested_recipient_role: string;
  suggested_settlement_mode: string;
  suggested_share_basis: string;
  suggested_share_value?: string | null;
  suggested_ratio_numerator?: string | null;
  suggested_ratio_denominator?: string | null;
  suggested_source_field_job_id?: string | null;
  suggestion_source?: 'AGREEMENT' | 'FIELD_JOB' | string;
  agreement_id?: string | null;
}

export interface HarvestShareTemplateLineRow {
  recipient_role: string;
  settlement_mode: string;
  share_basis: string;
  share_value?: string | null;
  ratio_numerator?: string | null;
  ratio_denominator?: string | null;
  remainder_bucket: boolean;
  beneficiary_party_id?: string | null;
  machine_id?: string | null;
  worker_id?: string | null;
  sort_order?: number | null;
  notes?: string | null;
  suggestion_source?: 'AGREEMENT' | 'HISTORY' | string;
  agreement_id?: string | null;
}

export interface HarvestShareTemplateBlock {
  template_source?: 'AGREEMENT' | 'HISTORY' | string;
  source_harvest_id?: string | null;
  source_harvest_no: string | null;
  source_harvest_date: string | null;
  lines: HarvestShareTemplateLineRow[];
}

export interface HarvestSuggestionsResponse {
  machine_suggestions: HarvestMachineSuggestionRow[];
  labour_suggestions: HarvestLabourSuggestionRow[];
  share_templates: HarvestShareTemplateBlock[];
  confidence: HarvestSuggestionConfidence;
}

/** Map API suggestion row to POST body (field copy only). */
export function harvestMachineSuggestionToPayload(
  s: HarvestMachineSuggestionRow,
  sortOrder: number
): HarvestShareLineCreatePayload {
  const basis = s.suggested_share_basis as HarvestShareBasis;
  const fromAgreement = s.suggestion_source === 'AGREEMENT';
  const notes = fromAgreement
    ? 'Suggested from agreement (machine)'
    : 'Suggested from field job machine usage';
  const payload: HarvestShareLineCreatePayload = {
    recipient_role: s.suggested_recipient_role as HarvestRecipientRole,
    settlement_mode: s.suggested_settlement_mode as HarvestSettlementMode,
    share_basis: basis,
    machine_id: s.machine_id,
    source_field_job_id: (s.suggested_source_field_job_id ?? s.field_job_id) || undefined,
    sort_order: sortOrder,
    remainder_bucket: false,
    notes,
  };
  if (basis === 'PERCENT' || basis === 'FIXED_QTY') {
    payload.share_value =
      s.suggested_share_value != null && s.suggested_share_value !== ''
        ? parseFloat(String(s.suggested_share_value))
        : null;
  }
  if (basis === 'RATIO') {
    payload.ratio_numerator = parseRatioNumber(s.suggested_ratio_numerator ?? '0');
    payload.ratio_denominator = parseRatioNumber(s.suggested_ratio_denominator ?? '1');
  }
  return payload;
}

export function harvestLabourSuggestionToPayload(
  s: HarvestLabourSuggestionRow,
  sortOrder: number
): HarvestShareLineCreatePayload {
  const basis = s.suggested_share_basis as HarvestShareBasis;
  const fromAgreement = s.suggestion_source === 'AGREEMENT';
  const notes = fromAgreement ? 'Suggested from agreement (labour)' : 'Suggested from field job labour';
  const payload: HarvestShareLineCreatePayload = {
    recipient_role: s.suggested_recipient_role as HarvestRecipientRole,
    settlement_mode: s.suggested_settlement_mode as HarvestSettlementMode,
    share_basis: basis,
    worker_id: s.worker_id,
    source_field_job_id: (s.suggested_source_field_job_id ?? s.field_job_id) || undefined,
    sort_order: sortOrder,
    remainder_bucket: false,
    notes,
  };
  if (basis === 'PERCENT' || basis === 'FIXED_QTY') {
    payload.share_value =
      s.suggested_share_value != null && s.suggested_share_value !== ''
        ? parseFloat(String(s.suggested_share_value))
        : null;
  }
  if (basis === 'RATIO') {
    payload.ratio_numerator = parseRatioNumber(s.suggested_ratio_numerator ?? '0');
    payload.ratio_denominator = parseRatioNumber(s.suggested_ratio_denominator ?? '1');
  }
  return payload;
}

export function harvestTemplateLineToPayload(
  line: HarvestShareTemplateLineRow,
  sortOrder: number
): HarvestShareLineCreatePayload {
  const basis = line.share_basis as HarvestShareBasis;
  const payload: HarvestShareLineCreatePayload = {
    recipient_role: line.recipient_role as HarvestRecipientRole,
    settlement_mode: line.settlement_mode as HarvestSettlementMode,
    share_basis: basis,
    sort_order: sortOrder,
    remainder_bucket: line.remainder_bucket,
    beneficiary_party_id: line.beneficiary_party_id ?? undefined,
    machine_id: line.machine_id ?? undefined,
    worker_id: line.worker_id ?? undefined,
    notes: line.notes ?? undefined,
  };
  if (basis === 'FIXED_QTY' || basis === 'PERCENT') {
    payload.share_value =
      line.share_value != null && line.share_value !== '' ? parseFloat(line.share_value) : null;
  }
  if (basis === 'RATIO') {
    payload.ratio_numerator =
      line.ratio_numerator != null && line.ratio_numerator !== ''
        ? parseFloat(line.ratio_numerator)
        : null;
    payload.ratio_denominator =
      line.ratio_denominator != null && line.ratio_denominator !== ''
        ? parseFloat(line.ratio_denominator)
        : null;
  }
  return payload;
}

function parseRatioNumber(s: string): number {
  const n = parseFloat(s);
  return Number.isFinite(n) ? n : 0;
}

/** Whether API validation would accept this row as a new share line (ratio or agreement terms). */
export function isApplicableFieldJobShareSuggestion(
  s: HarvestMachineSuggestionRow | HarvestLabourSuggestionRow
): boolean {
  const basis = s.suggested_share_basis;
  if (basis === 'PERCENT') {
    const p =
      s.suggested_share_value != null && s.suggested_share_value !== ''
        ? parseFloat(String(s.suggested_share_value))
        : NaN;
    return Number.isFinite(p) && p >= 0 && p <= 100;
  }
  if (basis === 'FIXED_QTY') {
    const q =
      s.suggested_share_value != null && s.suggested_share_value !== ''
        ? parseFloat(String(s.suggested_share_value))
        : NaN;
    return Number.isFinite(q) && q > 0;
  }
  const n = parseFloat(String(s.suggested_ratio_numerator ?? ''));
  const d = parseFloat(String(s.suggested_ratio_denominator ?? ''));
  return Number.isFinite(n) && Number.isFinite(d) && n > 0 && d > 0;
}

/** Whether template line can be posted as-is (minimal checks aligned with AddHarvestShareLineRequest). */
export function isApplicableTemplateLine(line: HarvestShareTemplateLineRow): boolean {
  const role = line.recipient_role;
  const basis = line.share_basis;
  if (role === 'MACHINE' && !line.machine_id) {
    return false;
  }
  if (role === 'LABOUR' && !line.worker_id) {
    return false;
  }
  if (basis === 'RATIO') {
    const n = line.ratio_numerator != null && line.ratio_numerator !== '' ? parseFloat(line.ratio_numerator) : NaN;
    const d = line.ratio_denominator != null && line.ratio_denominator !== '' ? parseFloat(line.ratio_denominator) : NaN;
    return Number.isFinite(n) && Number.isFinite(d) && n > 0 && d > 0;
  }
  if (basis === 'PERCENT') {
    const p = line.share_value != null && line.share_value !== '' ? parseFloat(line.share_value) : NaN;
    return Number.isFinite(p) && p >= 0 && p <= 100;
  }
  if (basis === 'FIXED_QTY') {
    const q = line.share_value != null && line.share_value !== '' ? parseFloat(line.share_value) : NaN;
    return Number.isFinite(q) && q > 0;
  }
  if (basis === 'REMAINDER') {
    return line.remainder_bucket === true;
  }
  return false;
}

const BASE = '/api/v1/crop-ops';

export interface HarvestFilters {
  status?: string;
  crop_cycle_id?: string;
  project_id?: string;
  production_unit_id?: string;
  from?: string;
  to?: string;
}

function searchParams(obj: Record<string, string | undefined> | object): string {
  const r = (obj || {}) as Record<string, string | undefined>;
  const p = new URLSearchParams();
  Object.entries(r).forEach(([k, v]) => { if (v) p.append(k, v); });
  const s = p.toString();
  return s ? `?${s}` : '';
}

export const harvestsApi = {
  list: (f?: HarvestFilters) => apiClient.get<Harvest[]>(`${BASE}/harvests${searchParams(f || {})}`),
  get: (id: string) => apiClient.get<Harvest>(`${BASE}/harvests/${id}`),
  create: (payload: CreateHarvestPayload) => apiClient.post<Harvest>(`${BASE}/harvests`, payload),
  update: (id: string, payload: UpdateHarvestPayload) => apiClient.put<Harvest>(`${BASE}/harvests/${id}`, payload),
  addLine: (id: string, payload: { inventory_item_id: string; store_id: string; quantity: number; uom?: string; notes?: string }) => 
    apiClient.post<HarvestLine>(`${BASE}/harvests/${id}/lines`, payload),
  updateLine: (id: string, lineId: string, payload: { inventory_item_id?: string; store_id?: string; quantity?: number; uom?: string; notes?: string }) => 
    apiClient.put<HarvestLine>(`${BASE}/harvests/${id}/lines/${lineId}`, payload),
  deleteLine: (id: string, lineId: string) => apiClient.delete(`${BASE}/harvests/${id}/lines/${lineId}`),
  post: (id: string, payload: PostHarvestPayload) => apiClient.post<Harvest>(`${BASE}/harvests/${id}/post`, payload),
  reverse: (id: string, payload: ReverseHarvestPayload) => apiClient.post<Harvest>(`${BASE}/harvests/${id}/reverse`, payload),

  addShareLine: (id: string, payload: HarvestShareLineCreatePayload) =>
    apiClient.post<Harvest>(`${BASE}/harvests/${id}/share-lines`, payload),

  updateShareLine: (id: string, shareLineId: string, payload: HarvestShareLinePayload) =>
    apiClient.put<Harvest>(`${BASE}/harvests/${id}/share-lines/${shareLineId}`, payload),

  deleteShareLine: (id: string, shareLineId: string) =>
    apiClient.delete(`${BASE}/harvests/${id}/share-lines/${shareLineId}`),

  /** Draft harvests only; uses WIP up to posting_date. */
  getSharePreview: (id: string, postingDate?: string) => {
    const q = postingDate ? `?posting_date=${encodeURIComponent(postingDate)}` : '';
    return apiClient.get<HarvestSharePreviewResponse>(`${BASE}/harvests/${id}/share-preview${q}`);
  },

  /** Read-only suggestions for draft harvests (no server-side mutation). */
  getSuggestions: (id: string) =>
    apiClient.get<HarvestSuggestionsResponse>(`${BASE}/harvests/${id}/suggestions`),

  /** Draft only: create share lines from resolved agreements (no post). */
  applyAgreements: (id: string, payload?: { overwrite?: boolean }) =>
    apiClient.post<{
      harvest: Harvest;
      created_count: number;
      replaced_existing: boolean;
      message: string | null;
    }>(`${BASE}/harvests/${id}/apply-agreements`, payload ?? {}),
};
