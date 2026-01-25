import { apiClient } from '@farm-erp/shared';
import type { Project, CreateProjectPayload, CreateProjectFromAllocationPayload } from '../types';

export const projectsApi = {
  list: (cropCycleId?: string) => {
    const query = cropCycleId ? `?crop_cycle_id=${cropCycleId}` : '';
    return apiClient.get<Project[]>(`/api/projects${query}`);
  },
  get: (id: string) => apiClient.get<Project>(`/api/projects/${id}`),
  create: (payload: CreateProjectPayload) => apiClient.post<Project>('/api/projects', payload),
  update: (id: string, payload: Partial<CreateProjectPayload>) => 
    apiClient.patch<Project>(`/api/projects/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/projects/${id}`),
  createFromAllocation: (payload: CreateProjectFromAllocationPayload) => 
    apiClient.post<Project>('/api/projects/from-allocation', payload),
};
