import { apiClient } from '@farm-erp/shared';
import type { SettlementPreview, PostSettlementRequest, SettlementPostResult, SettlementOffsetPreview } from '../types';

// Project-based settlements (existing, for backward compatibility)
export const settlementApi = {
  preview: (projectId: string, upToDate?: string) => {
    const payload = upToDate ? { up_to_date: upToDate } : {};
    return apiClient.post<SettlementPreview>(`/api/projects/${projectId}/settlement/preview`, payload);
  },
  offsetPreview: (projectId: string, postingDate: string) => {
    const q = new URLSearchParams({ posting_date: postingDate }).toString();
    return apiClient.get<SettlementOffsetPreview>(`/api/projects/${projectId}/settlement/offset-preview?${q}`);
  },
  post: (projectId: string, payload: PostSettlementRequest) => 
    apiClient.post<SettlementPostResult>(`/api/projects/${projectId}/settlement/post`, payload),
};

// Sales-based settlements (Phase 11)
export interface SalesSettlement {
  id: string;
  tenant_id: string;
  settlement_no: string;
  share_rule_id: string;
  crop_cycle_id?: string | null;
  from_date?: string | null;
  to_date?: string | null;
  basis_amount: string;
  status: 'DRAFT' | 'POSTED' | 'REVERSED';
  posting_date?: string | null;
  posting_group_id?: string | null;
  reversal_posting_group_id?: string | null;
  posted_at?: string | null;
  reversed_at?: string | null;
  created_by?: string | null;
  created_at: string;
  share_rule?: {
    id: string;
    name: string;
    basis: string;
  };
  crop_cycle?: {
    id: string;
    name: string;
  };
  lines?: Array<{
    id: string;
    party_id: string;
    role?: string | null;
    percentage: string;
    amount: string;
    party?: {
      id: string;
      name: string;
    };
  }>;
  sales?: Array<{
    id: string;
    sale_no?: string | null;
    posting_date: string;
  }>;
}

export interface SettlementPreviewResult {
  sales: Array<{
    id: string;
    sale_no?: string | null;
    posting_date: string;
    revenue: number;
    cogs: number;
    margin: number;
  }>;
  total_revenue: number;
  total_cogs: number;
  total_margin: number;
  share_rule: {
    id: string;
    name: string;
    basis: string;
  };
  basis_amount: number;
  party_amounts: Array<{
    party_id: string;
    party_name: string;
    role?: string | null;
    percentage: number;
    amount: number;
  }>;
}

export interface CreateSettlementPayload {
  sale_ids: string[];
  share_rule_id: string;
  crop_cycle_id?: string;
  from_date?: string;
  to_date?: string;
  settlement_no?: string;
}

export interface SettlementFilters {
  status?: string;
  crop_cycle_id?: string;
  share_rule_id?: string;
}

export const salesSettlementApi = {
  preview: (filters: {
    crop_cycle_id?: string;
    from_date?: string;
    to_date?: string;
    share_rule_id?: string;
  }) => {
    const params = new URLSearchParams();
    if (filters.crop_cycle_id) params.append('crop_cycle_id', filters.crop_cycle_id);
    if (filters.from_date) params.append('from_date', filters.from_date);
    if (filters.to_date) params.append('to_date', filters.to_date);
    if (filters.share_rule_id) params.append('share_rule_id', filters.share_rule_id);
    
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<SettlementPreviewResult>(`/api/settlements/preview${query}`);
  },
  list: (filters?: SettlementFilters) => {
    const params = new URLSearchParams();
    if (filters?.status) params.append('status', filters.status);
    if (filters?.crop_cycle_id) params.append('crop_cycle_id', filters.crop_cycle_id);
    if (filters?.share_rule_id) params.append('share_rule_id', filters.share_rule_id);
    
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<SalesSettlement[]>(`/api/settlements${query}`);
  },
  get: (id: string) => apiClient.get<SalesSettlement>(`/api/settlements/${id}`),
  create: (payload: CreateSettlementPayload) => 
    apiClient.post<SalesSettlement>('/api/settlements', payload),
  post: (id: string, postingDate: string) => 
    apiClient.post<{ settlement: SalesSettlement; posting_group: any }>(`/api/settlements/${id}/post`, { posting_date: postingDate }),
  reverse: (id: string, reversalDate: string) => 
    apiClient.post<{ settlement: SalesSettlement; reversal_posting_group: any }>(`/api/settlements/${id}/reverse`, { reversal_date: reversalDate }),
};
