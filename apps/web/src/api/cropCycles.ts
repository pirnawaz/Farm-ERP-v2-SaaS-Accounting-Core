import { apiClient } from '@farm-erp/shared';
import type { CropCycle, CreateCropCyclePayload, CropCycleClosePreview } from '../types';

export interface SeasonSetupAssignment {
  land_parcel_id: string;
  blocks: {
    tenant_crop_item_id: string;
    name?: string;
    area: number;
    agreement_id?: string | null;
    agreement_allocation_id?: string | null;
  }[];
}

export interface SeasonSetupPayload {
  assignments: SeasonSetupAssignment[];
}

export interface SeasonSetupResponse {
  crop_cycle_id: string;
  field_blocks_created?: number;
  projects_created: number;
  projects: {
    field_block_id?: string;
    project_id: string;
    name: string;
    land_parcel_id: string;
    land_allocation_id: string;
  }[];
  results?: Array<{
    index: number;
    status: 'ok' | 'error';
    land_parcel_id: string;
    land_allocation_id?: string;
    total_allocated_acres?: number;
    message?: string;
  }>;
}

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
  seasonSetup: (cropCycleId: string, payload: SeasonSetupPayload) =>
    apiClient.post<SeasonSetupResponse>(`/api/crop-cycles/${cropCycleId}/season-setup`, payload),
};
