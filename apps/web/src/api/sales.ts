import { apiClient } from '@farm-erp/shared';
import type { Sale, CreateSalePayload, PostSaleRequest, PostingGroup } from '../types';

export interface SaleFilters {
  status?: string;
  buyer_party_id?: string;
  project_id?: string;
  date_from?: string;
  date_to?: string;
}

export const salesApi = {
  list: (filters?: SaleFilters) => {
    const params = new URLSearchParams();
    if (filters?.status) params.append('status', filters.status);
    if (filters?.buyer_party_id) params.append('buyer_party_id', filters.buyer_party_id);
    if (filters?.project_id) params.append('project_id', filters.project_id);
    if (filters?.date_from) params.append('date_from', filters.date_from);
    if (filters?.date_to) params.append('date_to', filters.date_to);
    
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<Sale[]>(`/api/sales${query}`);
  },
  get: (id: string) => apiClient.get<Sale>(`/api/sales/${id}`),
  create: (payload: CreateSalePayload) => 
    apiClient.post<Sale>('/api/sales', payload),
  update: (id: string, payload: Partial<CreateSalePayload>) => 
    apiClient.put<Sale>(`/api/sales/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/sales/${id}`),
  post: (id: string, payload: PostSaleRequest) => 
    apiClient.post<PostingGroup>(`/api/sales/${id}/post`, payload),
};
