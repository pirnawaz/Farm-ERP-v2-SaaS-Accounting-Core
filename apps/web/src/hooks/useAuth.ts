import { useState, useEffect, useCallback } from 'react';
import { apiClient } from '@farm-erp/shared';
import type { UserRole } from '../types';

const USER_ROLE_KEY = 'farm_erp_user_role'; // Fallback for dev mode only

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
    try {
      setIsLoading(true);
      const user = await apiClient.get<{ user_id: string; role: UserRole; tenant_id: string; email?: string }>('/api/auth/me');
      setUserRoleState(user.role);
      setUserId(user.user_id);
      setTenantId(user.tenant_id);
      // Clear localStorage fallback when cookie auth succeeds
      localStorage.removeItem(USER_ROLE_KEY);
    } catch (error) {
      // Not authenticated via cookie - check localStorage fallback (dev mode only)
      const storedRole = localStorage.getItem(USER_ROLE_KEY);
      if (storedRole) {
        setUserRoleState(storedRole as UserRole);
      } else {
        setUserRoleState(null);
        setUserId(null);
        setTenantId(null);
      }
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
