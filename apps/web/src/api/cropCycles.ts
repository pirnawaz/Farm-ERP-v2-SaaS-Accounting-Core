import { apiClient } from '@farm-erp/shared';
import type { CropCycle, CreateCropCyclePayload, CropCycleClosePreview } from '../types';

export const cropCyclesApi = {
  list: () => apiClient.get<CropCycle[]>('/api/crop-cycles'),
  get: (id: string) => apiClient.get<CropCycle>(`/api/crop-cycles/${id}`),
  create: (payload: CreateCropCyclePayload) => apiClient.post<CropCycle>('/api/crop-cycles', payload),
  update: (id: string, payload: Partial<CreateCropCyclePayload>) =>
    apiClient.patch<CropCycle>(`/api/crop-cycles/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/crop-cycles/${id}`),
  closePreview: (id: string) => apiClient.get<CropCycleClosePreview>(`/api/crop-cycles/${id}/close-preview`),
  close: (id: string, body?: { note?: string }) =>
    apiClient.post<CropCycle>(`/api/crop-cycles/${id}/close`, body ?? {}),
  reopen: (id: string) => apiClient.post<CropCycle>(`/api/crop-cycles/${id}/reopen`, {}),
  open: (id: string) => apiClient.post<CropCycle>(`/api/crop-cycles/${id}/open`, {}),
};
