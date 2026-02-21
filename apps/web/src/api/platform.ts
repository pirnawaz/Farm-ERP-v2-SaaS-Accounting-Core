import { apiClient } from '@farm-erp/shared';
import type {
  PlatformTenant,
  CreatePlatformTenantPayload,
  UpdatePlatformTenantPayload,
  ImpersonationStatus,
} from '../types';

export interface PlatformTenantDetail extends PlatformTenant {
  farm?: {
    id: string;
    farm_name: string;
    country?: string | null;
    address_line1?: string | null;
    address_line2?: string | null;
    city?: string | null;
    region?: string | null;
    postal_code?: string | null;
    phone?: string | null;
  } | null;
}

export interface PlatformLoginResponse {
  user_id: string;
  role: string;
  tenant_id: null;
  email: string;
  name?: string;
  is_platform_admin: boolean;
}

export interface PlatformMeResponse {
  user_id: string;
  name: string;
  email: string;
  roles: string[];
  is_platform_admin: boolean;
}

export interface PlatformTenantModuleItem {
  key: string;
  name: string;
  description: string | null;
  is_core: boolean;
  sort_order: number;
  enabled: boolean;
  status: string;
  allowed_by_plan: boolean;
  enabled_at: string | null;
  disabled_at: string | null;
}

export interface PlatformTenantModulesResponse {
  modules: PlatformTenantModuleItem[];
  plan_key: string | null;
}

export interface PlatformAuditLogItem {
  id: string;
  tenant_id: string;
  tenant_name?: string;
  entity_type: string;
  entity_id: string;
  action: string;
  user_id: string;
  user_email: string | null;
  actor_name?: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface PlatformAuditLogsParams {
  tenant_id?: string;
  actor_user_id?: string;
  action?: string;
  date_from?: string;
  date_to?: string;
  per_page?: number;
  page?: number;
}

export interface PlatformAuditLogsResponse {
  data: PlatformAuditLogItem[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const platformApi = {
  login: (email: string, password: string) =>
    apiClient.post<PlatformLoginResponse>('/api/platform/auth/login', { email, password }),
  logout: () =>
    apiClient.post<{ message: string }>('/api/platform/auth/logout', {}),
  me: () =>
    apiClient.get<PlatformMeResponse>('/api/platform/auth/me'),
  listTenants: () =>
    apiClient.get<{ tenants: PlatformTenant[] }>('/api/platform/tenants'),
  getTenant: (id: string) =>
    apiClient.get<PlatformTenantDetail>('/api/platform/tenants/' + id),
  getTenantModules: (tenantId: string) =>
    apiClient.get<PlatformTenantModulesResponse>('/api/platform/tenants/' + tenantId + '/modules'),
  updateTenantModules: (tenantId: string, payload: { modules: Array<{ key: string; enabled: boolean }> }) =>
    apiClient.put<PlatformTenantModulesResponse>('/api/platform/tenants/' + tenantId + '/modules', payload),
  createTenant: (payload: CreatePlatformTenantPayload) =>
    apiClient.post<{ tenant: PlatformTenant }>('/api/platform/tenants', payload),
  updateTenant: (id: string, payload: UpdatePlatformTenantPayload) =>
    apiClient.put<PlatformTenant>('/api/platform/tenants/' + id, payload),
  getImpersonationStatus: () =>
    apiClient.get<ImpersonationStatus>('/api/platform/impersonation'),
  startImpersonation: (tenantId: string, userId?: string) =>
    apiClient.post<{ message: string; target_tenant_id: string; target_user_id?: string }>(
      '/api/platform/impersonation/start',
      userId ? { tenant_id: tenantId, user_id: userId } : { tenant_id: tenantId }
    ),
  stopImpersonation: (targetTenantId?: string) =>
    apiClient.post<{ message: string }>(
      '/api/platform/impersonation/stop',
      targetTenantId ? { target_tenant_id: targetTenantId } : {}
    ),
  getAuditLogs: (params?: PlatformAuditLogsParams) => {
    if (!params) return apiClient.get<PlatformAuditLogsResponse>('/api/platform/audit-logs');
    const sp = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') sp.append(k, String(v));
    });
    const search = sp.toString() ? '?' + sp.toString() : '';
    return apiClient.get<PlatformAuditLogsResponse>('/api/platform/audit-logs' + search);
  },
  resetTenantAdminPassword: (tenantId: string, newPassword?: string) =>
    apiClient.post<{ message: string; reset_token?: string; expires_in_minutes?: number }>(
      '/api/platform/tenants/' + tenantId + '/reset-admin-password',
      newPassword ? { new_password: newPassword } : {}
    ),
  archiveTenant: (tenantId: string) =>
    apiClient.post<{ message: string; id: string; status: string }>(
      '/api/platform/tenants/' + tenantId + '/archive',
      {}
    ),
  unarchiveTenant: (tenantId: string) =>
    apiClient.post<{ message: string; id: string; status: string }>(
      '/api/platform/tenants/' + tenantId + '/unarchive',
      {}
    ),
};
