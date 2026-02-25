import { apiClient } from '@farm-erp/shared';
import type { ProductionUnit, CreateProductionUnitPayload } from '../types';

type ListParams = { status?: string; type?: string; category?: string; orchard_crop?: string };

export const productionUnitsApi = {
  list: (params?: ListParams) => {
    const search = new URLSearchParams();
    if (params?.status) search.set('status', params.status);
    if (params?.type) search.set('type', params.type);
    if (params?.category) search.set('category', params.category);
    if (params?.orchard_crop) search.set('orchard_crop', params.orchard_crop);
    const q = search.toString();
    return apiClient.get<ProductionUnit[]>(`/api/production-units${q ? `?${q}` : ''}`);
  },
  get: (id: string) => apiClient.get<ProductionUnit>(`/api/production-units/${id}`),
  create: (payload: CreateProductionUnitPayload) =>
    apiClient.post<ProductionUnit>('/api/production-units', payload),
  update: (id: string, payload: Partial<CreateProductionUnitPayload>) =>
    apiClient.patch<ProductionUnit>(`/api/production-units/${id}`, payload),
};
