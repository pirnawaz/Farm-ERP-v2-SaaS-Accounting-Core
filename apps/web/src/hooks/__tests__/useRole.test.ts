import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useRole } from '../useRole';

vi.mock('../useAuth', () => ({
  useAuth: vi.fn(),
}));

import { useAuth } from '../useAuth';

const defaultAuth = {
  userRole: null as string | null,
  userId: null,
  tenantId: null,
  mode: null,
  mustChangePassword: false,
  isLoading: false,
  checkAuth: vi.fn(),
  setDevIdentity: vi.fn(),
  setIdentityFromUnifiedLogin: vi.fn(),
  setUserRole: vi.fn(),
  setMustChangePassword: vi.fn(),
  setTenantIdFromImpersonation: vi.fn(),
  logout: vi.fn(),
};

describe('useRole', () => {
  beforeEach(() => {
    vi.mocked(useAuth).mockReturnValue({ ...defaultAuth, userRole: null });
  });

  it('returns false for hasRole and capabilities when not authenticated', () => {
    const { result } = renderHook(() => useRole());
    expect(result.current.hasRole('tenant_admin')).toBe(false);
    expect(result.current.hasRole(['tenant_admin', 'accountant'])).toBe(false);
    expect(result.current.canPost).toBe(false);
    expect(result.current.canManageUsers).toBe(false);
    expect(result.current.can('tenant.users_manage')).toBe(false);
  });

  it('tenant_admin: hasRole, canPost, canManageUsers, can close cycle', () => {
    vi.mocked(useAuth).mockReturnValue({ ...defaultAuth, userRole: 'tenant_admin' });
    const { result } = renderHook(() => useRole());
    expect(result.current.userRole).toBe('tenant_admin');
    expect(result.current.hasRole('tenant_admin')).toBe(true);
    expect(result.current.hasRole(['accountant', 'tenant_admin'])).toBe(true);
    expect(result.current.canPost).toBe(true);
    expect(result.current.canManageUsers).toBe(true);
    expect(result.current.canCloseCropCycle).toBe(true);
    expect(result.current.can('tenant.users_manage')).toBe(true);
    expect(result.current.can('tenant.modules_manage')).toBe(true);
  });

  it('accountant: canPost, cannot manage users or close cycle', () => {
    vi.mocked(useAuth).mockReturnValue({ ...defaultAuth, userRole: 'accountant' });
    const { result } = renderHook(() => useRole());
    expect(result.current.hasRole('accountant')).toBe(true);
    expect(result.current.hasRole('tenant_admin')).toBe(false);
    expect(result.current.canPost).toBe(true);
    expect(result.current.canManageUsers).toBe(false);
    expect(result.current.canCloseCropCycle).toBe(false);
    expect(result.current.can('tenant.users_manage')).toBe(false);
    expect(result.current.can('tenant.post_to_accounts')).toBe(true);
  });

  it('operator: cannot post, cannot manage users', () => {
    vi.mocked(useAuth).mockReturnValue({ ...defaultAuth, userRole: 'operator' });
    const { result } = renderHook(() => useRole());
    expect(result.current.hasRole('operator')).toBe(true);
    expect(result.current.canPost).toBe(false);
    expect(result.current.canManageUsers).toBe(false);
    expect(result.current.canCloseCropCycle).toBe(false);
    expect(result.current.can('tenant.post_to_accounts')).toBe(false);
    expect(result.current.can('tenant.view_all_data')).toBe(true);
  });

  it('hasRole with wrong role returns false', () => {
    vi.mocked(useAuth).mockReturnValue({ ...defaultAuth, userRole: 'operator' });
    const { result } = renderHook(() => useRole());
    expect(result.current.hasRole('tenant_admin')).toBe(false);
    expect(result.current.hasRole(['tenant_admin', 'accountant'])).toBe(false);
  });
});
