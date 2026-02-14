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

export const platformApi = {
  listTenants: () =>
    apiClient.get<{ tenants: PlatformTenant[] }>('/api/platform/tenants'),
  getTenant: (id: string) =>
    apiClient.get<PlatformTenantDetail>('/api/platform/tenants/' + id),
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
};
