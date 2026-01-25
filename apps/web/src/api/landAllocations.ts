import { apiClient } from '@farm-erp/shared';
import type { LandAllocation, CreateLandAllocationPayload } from '../types';

export const landAllocationsApi = {
  list: (cropCycleId?: string) => {
    const query = cropCycleId ? `?crop_cycle_id=${cropCycleId}` : '';
    return apiClient.get<LandAllocation[]>(`/api/land-allocations${query}`);
  },
  get: (id: string) => apiClient.get<LandAllocation>(`/api/land-allocations/${id}`),
  create: (payload: CreateLandAllocationPayload) => 
    apiClient.post<LandAllocation>('/api/land-allocations', payload),
  update: (id: string, payload: Partial<CreateLandAllocationPayload>) => 
    apiClient.patch<LandAllocation>(`/api/land-allocations/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/land-allocations/${id}`),
};
