import { apiClient } from '@farm-erp/shared';
import type { User, CreateUserPayload } from '../types';

export const usersApi = {
  list: () => apiClient.get<User[]>('/api/users'),
  get: (id: string) => apiClient.get<User>(`/api/users/${id}`),
  create: (payload: CreateUserPayload) => apiClient.post<User>('/api/users', payload),
  update: (id: string, payload: Partial<CreateUserPayload>) => 
    apiClient.patch<User>(`/api/users/${id}`, payload),
  /** Soft-disables the user (same as tenant user remove); row is kept for audit. */
  delete: (id: string) => apiClient.delete(`/api/users/${id}`),
};
