import { apiClient } from '@farm-erp/shared';
import type { CropCycle, CreateCropCyclePayload } from '../types';

export const cropCyclesApi = {
  list: () => apiClient.get<CropCycle[]>('/api/crop-cycles'),
  get: (id: string) => apiClient.get<CropCycle>(`/api/crop-cycles/${id}`),
  create: (payload: CreateCropCyclePayload) => apiClient.post<CropCycle>('/api/crop-cycles', payload),
  update: (id: string, payload: Partial<CreateCropCyclePayload>) => 
    apiClient.patch<CropCycle>(`/api/crop-cycles/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/crop-cycles/${id}`),
  close: (id: string) => apiClient.post(`/api/crop-cycles/${id}/close`, {}),
  open: (id: string) => apiClient.post(`/api/crop-cycles/${id}/open`, {}),
};
