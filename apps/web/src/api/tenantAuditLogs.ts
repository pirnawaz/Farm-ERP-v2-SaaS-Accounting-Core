import { apiClient } from '@farm-erp/shared';

export interface TenantAuditLogItem {
  id: string;
  created_at: string;
  actor: { id: string; email: string; name: string } | null;
  action: string;
  metadata: Record<string, unknown> | null;
  ip: string | null;
  user_agent: string | null;
}

export interface TenantAuditLogsParams {
  action?: string;
  from?: string;
  to?: string;
  q?: string;
  per_page?: number;
  page?: number;
}

export interface TenantAuditLogsResponse {
  data: TenantAuditLogItem[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const tenantAuditLogsApi = {
  getAuditLogs: (params?: TenantAuditLogsParams) => {
    if (!params) return apiClient.get<TenantAuditLogsResponse>('/api/tenant/audit-logs');
    const sp = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') sp.append(k, String(v));
    });
    const search = sp.toString() ? '?' + sp.toString() : '';
    return apiClient.get<TenantAuditLogsResponse>('/api/tenant/audit-logs' + search);
  },
};
