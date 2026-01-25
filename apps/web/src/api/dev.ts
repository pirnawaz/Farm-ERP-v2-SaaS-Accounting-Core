import { apiClient } from '@farm-erp/shared';
import type { Tenant } from '@farm-erp/shared';

export interface CreateTenantPayload {
  name: string;
}

export interface TenantResponse {
  tenant: Tenant;
}

export interface TenantsResponse {
  tenants: Tenant[];
}

export const devApi = {
  listTenants: () => apiClient.get<TenantsResponse>('/api/dev/tenants'),
  createTenant: (payload: CreateTenantPayload) => 
    apiClient.post<TenantResponse>('/api/dev/tenants', payload),
  activateTenant: (id: string) => 
    apiClient.post<TenantResponse>(`/api/dev/tenants/${id}/activate`, {}),
};
