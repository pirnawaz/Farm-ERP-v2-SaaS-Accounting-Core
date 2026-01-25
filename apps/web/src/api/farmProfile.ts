import { apiClient } from '@farm-erp/shared';
import type { FarmProfile, UpdateFarmProfilePayload } from '../types';

export const farmProfileApi = {
  get: () => apiClient.get<FarmProfile>('/api/tenant/farm-profile'),
  update: (payload: UpdateFarmProfilePayload) =>
    apiClient.put<FarmProfile>('/api/tenant/farm-profile', payload),
};
