import { apiClient } from '@farm-erp/shared';
import type { TenantModulesResponse, UpdateTenantModulesPayload } from '@farm-erp/shared';

export const modulesApi = {
  getTenantModules: () =>
    apiClient.get<TenantModulesResponse>('/api/tenant/modules'),

  updateTenantModules: (payload: UpdateTenantModulesPayload) =>
    apiClient.put<TenantModulesResponse>('/api/tenant/modules', payload),
};
