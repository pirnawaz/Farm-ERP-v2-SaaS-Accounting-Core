import { apiClient } from '@farm-erp/shared';
import type { User, CreateTenantUserPayload, UpdateTenantUserPayload } from '../types';

export const tenantUsersApi = {
  list: () => apiClient.get<User[]>('/api/tenant/users'),
  create: (payload: CreateTenantUserPayload) => apiClient.post<User>('/api/tenant/users', payload),
  update: (id: string, payload: UpdateTenantUserPayload) =>
    apiClient.put<User>(`/api/tenant/users/${id}`, payload),
  disable: (id: string) => apiClient.delete(`/api/tenant/users/${id}`),
};
