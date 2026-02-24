import { apiClient } from '@farm-erp/shared';
import type { CropItem, CreateCropItemPayload } from '../types';

export const cropItemsApi = {
  list: () => apiClient.get<CropItem[]>('/api/crop-items'),
  create: (payload: CreateCropItemPayload) =>
    apiClient.post<CropItem>('/api/crop-items', payload),
  update: (id: string, payload: Partial<Pick<CropItem, 'display_name'> & { is_active?: boolean; sort_order?: number }>) =>
    apiClient.patch<CropItem>(`/api/crop-items/${id}`, payload),
};
