import { apiClient } from '@farm-erp/shared';
import type {
  PlatformTenant,
  CreatePlatformTenantPayload,
  UpdatePlatformTenantPayload,
  ImpersonationStatus,
  ImpersonationStatusForUi,
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
  token: string;
  user: { id: string; name: string; email: string; role: string };
  tenant: null;
}

export interface PlatformMeResponse {
  user: { id: string; name: string; email: string; role: string };
  tenant: null;
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

export interface PlatformTenantUser {
  id: string;
  name: string;
  email: string;
  role: string;
  is_enabled: boolean;
  created_at: string;
}

export interface PlatformAuditLogItem {
  id: string;
  created_at: string;
  actor: { id: string; email: string; name: string } | null;
  action: string;
  metadata: Record<string, unknown> | null;
  ip: string | null;
  user_agent: string | null;
  tenant_id: string | null;
  tenant_name?: string | null;
}

export interface PlatformAuditLogsParams {
  tenant_id?: string;
  action?: string;
  from?: string;
  to?: string;
  q?: string;
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
  getTenantUsers: (tenantId: string) =>
    apiClient.get<{ users: PlatformTenantUser[] }>('/api/platform/tenants/' + tenantId + '/users'),
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
  /** For UI banner: callable when impersonation cookie is set (e.g. in tenant app). */
  getImpersonationStatusForUi: () =>
    apiClient.get<ImpersonationStatusForUi>('/api/platform/impersonation/status'),
  startImpersonation: (tenantId: string, userId?: string) =>
    apiClient.post<{ message: string; target_tenant_id: string; target_user_id?: string }>(
      '/api/platform/impersonation/start',
      userId ? { tenant_id: tenantId, user_id: userId } : { tenant_id: tenantId }
    ),
  /** Tenant-scoped impersonate: POST /api/platform/tenants/{tenantId}/impersonate with optional user_id */
  impersonateTenant: (tenantId: string, userId?: string) =>
    apiClient.post<{ message: string; target_tenant_id: string; target_user_id: string }>(
      '/api/platform/tenants/' + tenantId + '/impersonate',
      userId ? { user_id: userId } : {}
    ),
  stopImpersonation: (targetTenantId?: string) =>
    apiClient.post<{ message: string }>(
      '/api/platform/impersonation/stop',
      targetTenantId ? { target_tenant_id: targetTenantId } : {}
    ),
  /** Clears impersonation cookies unconditionally (platform_admin). Use when normal stop fails. */
  forceStopImpersonation: () =>
    apiClient.post<{ message: string }>('/api/platform/impersonation/force-stop', {}),
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
  /** Create a user in a tenant (manual, no email). Returns user + temporary_password. */
  createTenantUser: (
    tenantId: string,
    payload: { name: string; email: string; role: 'tenant_admin' | 'accountant' | 'operator'; temporary_password?: string }
  ) =>
    apiClient.post<{ user: { id: string; name: string; email: string; role: string }; temporary_password: string }>(
      '/api/platform/tenants/' + tenantId + '/users',
      payload
    ),
  /** Update a tenant user (role and/or is_enabled). */
  updateTenantUser: (
    tenantId: string,
    userId: string,
    payload: { role?: 'tenant_admin' | 'accountant' | 'operator'; is_enabled?: boolean }
  ) =>
    apiClient.patch<PlatformTenantUser>(
      '/api/platform/tenants/' + tenantId + '/users/' + userId,
      payload
    ),
  /** Platform invite a user into a tenant. Returns invite_link, expires_in_hours, email, role. */
  platformInviteTenantUser: (
    tenantId: string,
    payload: { email: string; role?: 'tenant_admin' | 'accountant' | 'operator' }
  ) =>
    apiClient.post<{
      invite_link: string;
      expires_in_hours: number;
      email: string;
      role: string;
    }>('/api/platform/tenants/' + tenantId + '/invitations', payload),
};
