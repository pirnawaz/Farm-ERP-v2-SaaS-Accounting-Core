import { apiClient } from '@farm-erp/shared';
import type {
  InvItem,
  InvStore,
  InvUom,
  InvItemCategory,
  InvGrn,
  InvIssue,
  InvTransfer,
  InvAdjustment,
  InvStockBalance,
  InvStockMovement,
  CreateInvGrnPayload,
  UpdateInvGrnPayload,
  PostInvGrnRequest,
  ReverseInvGrnRequest,
  CreateInvIssuePayload,
  UpdateInvIssuePayload,
  PostInvIssueRequest,
  ReverseInvIssueRequest,
  CreateInvTransferPayload,
  UpdateInvTransferPayload,
  PostInvTransferRequest,
  ReverseInvTransferRequest,
  CreateInvAdjustmentPayload,
  UpdateInvAdjustmentPayload,
  PostInvAdjustmentRequest,
  ReverseInvAdjustmentRequest,
  PostingGroup,
} from '../types';

const BASE = '/api/v1/inventory';

export interface GrnFilters {
  status?: string;
  store_id?: string;
}

export interface IssueFilters {
  status?: string;
  store_id?: string;
  project_id?: string;
}

export interface TransferFilters {
  status?: string;
  from_store_id?: string;
  to_store_id?: string;
  from?: string;
  to?: string;
}

export interface AdjustmentFilters {
  status?: string;
  store_id?: string;
  reason?: string;
  from?: string;
  to?: string;
}

export interface StockOnHandFilters {
  store_id?: string;
  item_id?: string;
}

export interface StockMovementsFilters {
  store_id?: string;
  item_id?: string;
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

export const inventoryApi = {
  items: {
    list: (is_active?: boolean) => {
      const q = is_active != null ? searchParams({ is_active: String(is_active) }) : '';
      return apiClient.get<InvItem[]>(`${BASE}/items${q}`);
    },
    get: (id: string) => apiClient.get<InvItem>(`${BASE}/items/${id}`),
    create: (payload: { name: string; sku?: string; category_id?: string; uom_id: string; valuation_method?: string; is_active?: boolean }) =>
      apiClient.post<InvItem>(`${BASE}/items`, payload),
    update: (id: string, payload: Partial<{ name: string; sku?: string; category_id?: string; uom_id: string; valuation_method?: string; is_active?: boolean }>) =>
      apiClient.patch<InvItem>(`${BASE}/items/${id}`, payload),
  },
  stores: {
    list: (is_active?: boolean) => {
      const q = is_active != null ? searchParams({ is_active: String(is_active) }) : '';
      return apiClient.get<InvStore[]>(`${BASE}/stores${q}`);
    },
    get: (id: string) => apiClient.get<InvStore>(`${BASE}/stores/${id}`),
    create: (payload: { name: string; type: 'MAIN' | 'FIELD' | 'OTHER'; is_active?: boolean }) =>
      apiClient.post<InvStore>(`${BASE}/stores`, payload),
    update: (id: string, payload: Partial<{ name: string; type: string; is_active?: boolean }>) =>
      apiClient.patch<InvStore>(`${BASE}/stores/${id}`, payload),
  },
  uoms: {
    list: () => apiClient.get<InvUom[]>(`${BASE}/uoms`),
    get: (id: string) => apiClient.get<InvUom>(`${BASE}/uoms/${id}`),
    create: (payload: { code: string; name: string }) => apiClient.post<InvUom>(`${BASE}/uoms`, payload),
    update: (id: string, payload: Partial<{ code: string; name: string }>) => apiClient.patch<InvUom>(`${BASE}/uoms/${id}`, payload),
  },
  categories: {
    list: () => apiClient.get<InvItemCategory[]>(`${BASE}/categories`),
    get: (id: string) => apiClient.get<InvItemCategory>(`${BASE}/categories/${id}`),
    create: (payload: { name: string }) => apiClient.post<InvItemCategory>(`${BASE}/categories`, payload),
    update: (id: string, payload: Partial<{ name: string }>) => apiClient.patch<InvItemCategory>(`${BASE}/categories/${id}`, payload),
  },
  grns: {
    list: (f?: GrnFilters) => apiClient.get<InvGrn[]>(`${BASE}/grns${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<InvGrn>(`${BASE}/grns/${id}`),
    create: (payload: CreateInvGrnPayload) => apiClient.post<InvGrn>(`${BASE}/grns`, payload),
    update: (id: string, payload: UpdateInvGrnPayload) => apiClient.patch<InvGrn>(`${BASE}/grns/${id}`, payload),
    post: (id: string, payload: PostInvGrnRequest) => apiClient.post<PostingGroup>(`${BASE}/grns/${id}/post`, payload),
    reverse: (id: string, payload: ReverseInvGrnRequest) => apiClient.post<PostingGroup>(`${BASE}/grns/${id}/reverse`, payload),
  },
  issues: {
    list: (f?: IssueFilters) => apiClient.get<InvIssue[]>(`${BASE}/issues${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<InvIssue>(`${BASE}/issues/${id}`),
    create: (payload: CreateInvIssuePayload) => apiClient.post<InvIssue>(`${BASE}/issues`, payload),
    update: (id: string, payload: UpdateInvIssuePayload) => apiClient.patch<InvIssue>(`${BASE}/issues/${id}`, payload),
    post: (id: string, payload: PostInvIssueRequest) => apiClient.post<PostingGroup>(`${BASE}/issues/${id}/post`, payload),
    reverse: (id: string, payload: ReverseInvIssueRequest) => apiClient.post<PostingGroup>(`${BASE}/issues/${id}/reverse`, payload),
  },
  transfers: {
    list: (f?: TransferFilters) => apiClient.get<InvTransfer[]>(`${BASE}/transfers${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<InvTransfer>(`${BASE}/transfers/${id}`),
    create: (payload: CreateInvTransferPayload) => apiClient.post<InvTransfer>(`${BASE}/transfers`, payload),
    update: (id: string, payload: UpdateInvTransferPayload) => apiClient.patch<InvTransfer>(`${BASE}/transfers/${id}`, payload),
    post: (id: string, payload: PostInvTransferRequest) => apiClient.post<PostingGroup>(`${BASE}/transfers/${id}/post`, payload),
    reverse: (id: string, payload: ReverseInvTransferRequest) => apiClient.post<PostingGroup>(`${BASE}/transfers/${id}/reverse`, payload),
  },
  adjustments: {
    list: (f?: AdjustmentFilters) => apiClient.get<InvAdjustment[]>(`${BASE}/adjustments${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<InvAdjustment>(`${BASE}/adjustments/${id}`),
    create: (payload: CreateInvAdjustmentPayload) => apiClient.post<InvAdjustment>(`${BASE}/adjustments`, payload),
    update: (id: string, payload: UpdateInvAdjustmentPayload) => apiClient.patch<InvAdjustment>(`${BASE}/adjustments/${id}`, payload),
    post: (id: string, payload: PostInvAdjustmentRequest) => apiClient.post<PostingGroup>(`${BASE}/adjustments/${id}/post`, payload),
    reverse: (id: string, payload: ReverseInvAdjustmentRequest) => apiClient.post<PostingGroup>(`${BASE}/adjustments/${id}/reverse`, payload),
  },
  stock: {
    onHand: (f?: StockOnHandFilters) => apiClient.get<InvStockBalance[]>(`${BASE}/stock/on-hand${searchParams(f || {})}`),
    movements: (f?: StockMovementsFilters) => apiClient.get<InvStockMovement[]>(`${BASE}/stock/movements${searchParams(f || {})}`),
  },
};
