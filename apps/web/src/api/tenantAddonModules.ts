import { apiClient } from '@farm-erp/shared';
import type { TenantAddonModulesResponse } from '../types';

export type TenantAddonModuleKey = 'orchards' | 'livestock';

export const tenantAddonModulesApi = {
  getTenantAddonModules: () =>
    apiClient.get<TenantAddonModulesResponse>('/api/tenant/addon-modules'),

  updateAddonModule: (moduleKey: TenantAddonModuleKey, isEnabled: boolean) =>
    apiClient.patch<TenantAddonModulesResponse>(
      `/api/tenant/addon-modules/${moduleKey}`,
      { is_enabled: isEnabled }
    ),
};
