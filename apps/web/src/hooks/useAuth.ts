import { useState, useEffect, useCallback } from 'react';
import type { UserRole } from '../types';

const AUTH_TOKEN_KEY = 'farm_erp_auth_token';
const USER_ROLE_KEY = 'farm_erp_user_role';

export function useAuth() {
  const [authToken, setAuthTokenState] = useState<string | null>(() => {
    return localStorage.getItem(AUTH_TOKEN_KEY);
  });

  const [userRole, setUserRoleState] = useState<UserRole | null>(() => {
    const stored = localStorage.getItem(USER_ROLE_KEY);
    return (stored as UserRole) || null;
  });

  const setAuthToken = useCallback((token: string | null) => {
    if (token) {
      localStorage.setItem(AUTH_TOKEN_KEY, token);
    } else {
      localStorage.removeItem(AUTH_TOKEN_KEY);
    }
    setAuthTokenState(token);
  }, []);

  const setUserRole = useCallback((role: UserRole) => {
    localStorage.setItem(USER_ROLE_KEY, role);
    setUserRoleState(role);
  }, []);

  const logout = useCallback(() => {
    localStorage.removeItem(AUTH_TOKEN_KEY);
    localStorage.removeItem(USER_ROLE_KEY);
    setAuthTokenState(null);
    setUserRoleState(null);
  }, []);

  useEffect(() => {
    const storedToken = localStorage.getItem(AUTH_TOKEN_KEY);
    const storedRole = localStorage.getItem(USER_ROLE_KEY);
    if (storedToken !== authToken) {
      setAuthTokenState(storedToken);
    }
    const roleValue = storedRole ? (storedRole as UserRole) : null;
    if (roleValue !== userRole) {
      setUserRoleState(roleValue);
    }
  }, [authToken, userRole]);

  return {
    authToken,
    userRole,
    setAuthToken,
    setUserRole,
    logout,
  };
}
