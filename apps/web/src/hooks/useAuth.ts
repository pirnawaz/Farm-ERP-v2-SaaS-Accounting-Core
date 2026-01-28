import { useState, useEffect, useCallback } from 'react';
import { apiClient } from '@farm-erp/shared';
import type { UserRole } from '../types';

const USER_ROLE_KEY = 'farm_erp_user_role';
const USER_ID_KEY = 'farm_erp_user_id';
const TENANT_ID_KEY = 'farm_erp_tenant_id';

export function useAuth() {
  const [userRole, setUserRoleState] = useState<UserRole | null>(() => {
    // Check localStorage as fallback for dev mode
    const stored = localStorage.getItem(USER_ROLE_KEY);
    return (stored as UserRole) || null;
  });
  const [userId, setUserId] = useState<string | null>(null);
  const [tenantId, setTenantId] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Check auth status on mount
  useEffect(() => {
    checkAuth();
  }, []);

  const checkAuth = useCallback(async () => {
    setIsLoading(true);
    
    // Check localStorage first (dev mode fallback)
    const storedRole = localStorage.getItem(USER_ROLE_KEY);
    if (storedRole) {
      setUserRoleState(storedRole as UserRole);
      setIsLoading(false);
      // Still try to verify with backend in the background (silently)
      apiClient.get<{ user_id: string; role: UserRole; tenant_id: string; email?: string }>('/api/auth/me')
        .then((user) => {
          // Cookie auth succeeded - use it and keep localStorage in sync so api-client sends X-User-Role etc.
          setUserRoleState(user.role);
          setUserId(user.user_id);
          setTenantId(user.tenant_id);
          localStorage.setItem(USER_ROLE_KEY, user.role);
          localStorage.setItem(USER_ID_KEY, user.user_id);
          localStorage.setItem(TENANT_ID_KEY, user.tenant_id);
        })
        .catch(() => {
          // Silently ignore - we're using localStorage fallback
        });
      return;
    }
    
    // No localStorage fallback - try cookie auth
    try {
      const user = await apiClient.get<{ user_id: string; role: UserRole; tenant_id: string; email?: string }>('/api/auth/me');
      setUserRoleState(user.role);
      setUserId(user.user_id);
      setTenantId(user.tenant_id);
      // Keep localStorage in sync so api-client always sends X-User-Role, X-Tenant-Id, X-User-Id
      localStorage.setItem(USER_ROLE_KEY, user.role);
      localStorage.setItem(USER_ID_KEY, user.user_id);
      localStorage.setItem(TENANT_ID_KEY, user.tenant_id);
    } catch (error) {
      // Not authenticated - clear state
      setUserRoleState(null);
      setUserId(null);
      setTenantId(null);
    } finally {
      setIsLoading(false);
    }
  }, []);

  const setUserRole = useCallback((role: UserRole) => {
    setUserRoleState(role);
    // Keep localStorage as fallback for dev mode
    localStorage.setItem(USER_ROLE_KEY, role);
  }, []);

  const logout = useCallback(async () => {
    try {
      await apiClient.post('/api/auth/logout', {});
    } catch (error) {
      // Ignore errors on logout
    }
    setUserRoleState(null);
    setUserId(null);
    setTenantId(null);
    localStorage.removeItem(USER_ROLE_KEY);
    localStorage.removeItem(USER_ID_KEY);
    localStorage.removeItem(TENANT_ID_KEY);
  }, []);

  return {
    userRole,
    userId,
    tenantId,
    setUserRole,
    logout,
    checkAuth,
    isLoading,
  };
}
