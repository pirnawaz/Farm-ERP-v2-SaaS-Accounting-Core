import { apiClient } from '@farm-erp/shared';

export interface TenantSettings {
  currency_code: string;
  locale: string;
  timezone: string;
}

export interface UpdateTenantSettingsPayload {
  currency_code: string;
  locale: string;
  timezone: string;
}

export const settingsApi = {
  getTenantSettings: () => apiClient.get<TenantSettings>('/api/settings/tenant'),
  updateTenantSettings: (payload: UpdateTenantSettingsPayload) =>
    apiClient.put<TenantSettings>('/api/settings/tenant', payload),
};
