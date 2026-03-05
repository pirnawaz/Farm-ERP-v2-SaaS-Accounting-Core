import { apiClient } from '@farm-erp/shared';

const UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export interface TenantLoginResponse {
  token: string;
  user: { id: string; name: string; email: string; role: string; must_change_password?: boolean };
  tenant: { id: string; name: string; slug?: string } | null;
}

/**
 * Complete first-login password update (user with must_change_password). Sends new_password; returns new cookie.
 */
export async function completeFirstLoginPassword(newPassword: string): Promise<{ message: string }> {
  return apiClient.post<{ message: string }>('/api/auth/complete-first-login-password', {
    new_password: newPassword,
    new_password_confirmation: newPassword,
  });
}

/**
 * Tenant login: send X-Tenant-Slug (default) or X-Tenant-Id. Pass tenantSlugOrId and set useTenantId=true to use UUID.
 */
export async function tenantLogin(
  tenantSlugOrId: string,
  email: string,
  password: string,
  options?: { useTenantId?: boolean }
): Promise<TenantLoginResponse> {
  const useId = options?.useTenantId ?? UUID_REGEX.test(tenantSlugOrId.trim());
  const headers: Record<string, string> = useId
    ? { 'X-Tenant-Id': tenantSlugOrId.trim() }
    : { 'X-Tenant-Slug': tenantSlugOrId.trim() };
  return apiClient.post<TenantLoginResponse>('/api/auth/login', { email, password }, { headers });
}

/**
 * Accept invite: token from URL, name + new_password. No tenant header. Returns same shape as login (sets cookie).
 */
export async function acceptInvite(
  token: string,
  name: string,
  newPassword: string
): Promise<TenantLoginResponse> {
  return apiClient.post<TenantLoginResponse>('/api/auth/accept-invite', {
    token,
    name,
    new_password: newPassword,
  });
}
