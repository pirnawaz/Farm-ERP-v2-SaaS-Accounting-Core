import { apiClient } from '@farm-erp/shared';
import type { Advance, CreateAdvancePayload, PostAdvanceRequest, PostingGroup } from '../types';

export interface AdvanceFilters {
  status?: string;
  type?: string;
  direction?: string;
  party_id?: string;
  date_from?: string;
  date_to?: string;
}

export const advancesApi = {
  list: (filters?: AdvanceFilters) => {
    const params = new URLSearchParams();
    if (filters?.status) params.append('status', filters.status);
    if (filters?.type) params.append('type', filters.type);
    if (filters?.direction) params.append('direction', filters.direction);
    if (filters?.party_id) params.append('party_id', filters.party_id);
    if (filters?.date_from) params.append('date_from', filters.date_from);
    if (filters?.date_to) params.append('date_to', filters.date_to);
    
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<Advance[]>(`/api/advances${query}`);
  },
  get: (id: string) => apiClient.get<Advance>(`/api/advances/${id}`),
  create: (payload: CreateAdvancePayload) => 
    apiClient.post<Advance>('/api/advances', payload),
  update: (id: string, payload: Partial<CreateAdvancePayload>) => 
    apiClient.put<Advance>(`/api/advances/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/advances/${id}`),
  post: (id: string, payload: PostAdvanceRequest) => 
    apiClient.post<PostingGroup>(`/api/advances/${id}/post`, payload),
};
