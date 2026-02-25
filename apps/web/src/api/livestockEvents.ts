import { apiClient } from '@farm-erp/shared';
import type { LivestockEvent, CreateLivestockEventPayload, UpdateLivestockEventPayload } from '../types';

export interface LivestockEventFilters {
  production_unit_id?: string;
  from?: string;
  to?: string;
}

export const livestockEventsApi = {
  list: (params?: LivestockEventFilters) => {
    const search = new URLSearchParams();
    if (params?.production_unit_id) search.set('production_unit_id', params.production_unit_id);
    if (params?.from) search.set('from', params.from);
    if (params?.to) search.set('to', params.to);
    const q = search.toString();
    return apiClient.get<LivestockEvent[]>(`/api/livestock-events${q ? `?${q}` : ''}`);
  },
  get: (id: string) => apiClient.get<LivestockEvent>(`/api/livestock-events/${id}`),
  create: (payload: CreateLivestockEventPayload) =>
    apiClient.post<LivestockEvent>('/api/livestock-events', payload),
  update: (id: string, payload: UpdateLivestockEventPayload) =>
    apiClient.patch<LivestockEvent>(`/api/livestock-events/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/livestock-events/${id}`),
};
