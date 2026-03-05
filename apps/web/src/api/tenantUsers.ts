import { apiClient } from '@farm-erp/shared';
import type { User, CreateTenantUserPayload, CreateTenantUserResponse, UpdateTenantUserPayload } from '../types';

export interface InviteUserPayload {
  email: string;
  role: string;
}

export interface InviteUserResponse {
  message: string;
  invite_link: string;
  expires_in_hours: number;
}

export const tenantUsersApi = {
  list: () => apiClient.get<User[]>('/api/tenant/users'),
  create: (payload: CreateTenantUserPayload) =>
    apiClient.post<CreateTenantUserResponse>('/api/tenant/users', payload),
  invite: (payload: InviteUserPayload) =>
    apiClient.post<InviteUserResponse>('/api/tenant/invitations', payload),
  update: (id: string, payload: UpdateTenantUserPayload) =>
    apiClient.put<User>(`/api/tenant/users/${id}`, payload),
  resetPassword: (id: string, newPassword: string) =>
    apiClient.post<{ message: string }>(`/api/tenant/users/${id}/reset-password`, { new_password: newPassword }),
  disable: (id: string) => apiClient.delete(`/api/tenant/users/${id}`),
};
