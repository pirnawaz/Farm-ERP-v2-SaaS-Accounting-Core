import { apiClient } from '@farm-erp/shared';
import type {
  TenantModulesResponse,
  TenantModulesUpdateResponse,
  UpdateTenantModulesPayload,
} from '@farm-erp/shared';

export const modulesApi = {
  getTenantModules: () =>
    apiClient.get<TenantModulesResponse>('/api/tenant/modules'),

  updateTenantModules: (payload: UpdateTenantModulesPayload) =>
    apiClient.put<TenantModulesUpdateResponse>('/api/tenant/modules', payload),
};
