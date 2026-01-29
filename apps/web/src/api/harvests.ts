import { apiClient } from '@farm-erp/shared';
import type {
  Harvest,
  HarvestLine,
  CreateHarvestPayload,
  UpdateHarvestPayload,
  PostHarvestPayload,
  ReverseHarvestPayload,
} from '../types';

const BASE = '/api/v1/crop-ops';

export interface HarvestFilters {
  status?: string;
  crop_cycle_id?: string;
  project_id?: string;
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
};
