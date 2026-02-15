import { apiClient } from '@farm-erp/shared';
import type {
  LandLease,
  CreateLandLeasePayload,
  UpdateLandLeasePayload,
} from '@farm-erp/shared';

export const landLeasesApi = {
  list: () => apiClient.get<LandLease[]>('/api/land-leases'),
  get: (id: string) => apiClient.get<LandLease>(`/api/land-leases/${id}`),
  create: (payload: CreateLandLeasePayload) =>
    apiClient.post<LandLease>('/api/land-leases', payload),
  update: (id: string, payload: UpdateLandLeasePayload) =>
    apiClient.put<LandLease>(`/api/land-leases/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/land-leases/${id}`),
};
