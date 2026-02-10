/**
 * E2E test data and env-driven defaults.
 * Use DEFAULT_TENANT_ID from .env.e2e when available.
 */
export function getDefaultTenantId(): string {
  return process.env.DEFAULT_TENANT_ID || '00000000-0000-0000-0000-000000000001';
}

export type UserRole = 'platform_admin' | 'tenant_admin' | 'accountant' | 'operator';

export const ROLES: UserRole[] = ['platform_admin', 'tenant_admin', 'accountant', 'operator'];
