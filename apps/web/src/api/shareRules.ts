import { apiClient } from '@farm-erp/shared';

export interface ShareRule {
  id: string;
  tenant_id: string;
  name: string;
  applies_to: 'CROP_CYCLE' | 'PROJECT' | 'SALE';
  basis: 'MARGIN' | 'REVENUE';
  effective_from: string;
  effective_to?: string | null;
  is_active: boolean;
  version: number;
  created_at: string;
  updated_at: string;
  lines?: ShareRuleLine[];
}

export interface ShareRuleLine {
  id: string;
  share_rule_id: string;
  party_id: string;
  percentage: string;
  role?: string | null;
  created_at: string;
  updated_at: string;
  party?: {
    id: string;
    name: string;
  };
}

export interface CreateShareRulePayload {
  name: string;
  applies_to: 'CROP_CYCLE' | 'PROJECT' | 'SALE';
  basis?: 'MARGIN' | 'REVENUE';
  effective_from: string;
  effective_to?: string | null;
  is_active?: boolean;
  lines: Array<{
    party_id: string;
    percentage: number;
    role?: string;
  }>;
}

export interface ShareRuleFilters {
  applies_to?: string;
  is_active?: boolean;
  crop_cycle_id?: string;
}

export const shareRulesApi = {
  list: (filters?: ShareRuleFilters) => {
    const params = new URLSearchParams();
    if (filters?.applies_to) params.append('applies_to', filters.applies_to);
    if (filters?.is_active !== undefined) params.append('is_active', String(filters.is_active));
    if (filters?.crop_cycle_id) params.append('crop_cycle_id', filters.crop_cycle_id);
    
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<ShareRule[]>(`/api/share-rules${query}`);
  },
  get: (id: string) => apiClient.get<ShareRule>(`/api/share-rules/${id}`),
  create: (payload: CreateShareRulePayload) => 
    apiClient.post<ShareRule>('/api/share-rules', payload),
  update: (id: string, payload: Partial<CreateShareRulePayload>) => 
    apiClient.put<ShareRule>(`/api/share-rules/${id}`, payload),
};
