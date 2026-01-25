import { apiClient } from '@farm-erp/shared';
import type { FarmProfile, UpdateFarmProfilePayload } from '../types';

export interface FarmProfileResponse {
  exists: boolean;
  farm: FarmProfile | null;
}

export const farmProfileApi = {
  get: () => apiClient.get<FarmProfileResponse>('/api/tenant/farm-profile'),
  create: (payload: UpdateFarmProfilePayload) =>
    apiClient.post<FarmProfile>('/api/tenant/farm-profile', payload),
  update: (payload: UpdateFarmProfilePayload) =>
    apiClient.put<FarmProfile>('/api/tenant/farm-profile', payload),
};
