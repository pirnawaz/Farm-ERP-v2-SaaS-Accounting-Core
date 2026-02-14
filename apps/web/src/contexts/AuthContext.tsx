import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { apiClient } from '@farm-erp/shared';
import type { UserRole } from '../types';

const USER_ROLE_KEY = 'farm_erp_user_role';
const USER_ID_KEY = 'farm_erp_user_id';
const TENANT_ID_KEY = 'farm_erp_tenant_id';

type AuthMeResponse = { user_id: string; role: UserRole; tenant_id: string; email?: string };

export type SetDevIdentityPayload = {
  role: UserRole;
  userId?: string | null;
  tenantId?: string | null;
};

type AuthContextValue = {
  userRole: UserRole | null;
  userId: string | null;
  tenantId: string | null;
  isLoading: boolean;
  checkAuth: () => Promise<void>;
  setDevIdentity: (payload: SetDevIdentityPayload) => void;
  setUserRole: (role: UserRole) => void;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

/** Dedupe in-flight checkAuth so only one runs at a time. */
let checkAuthPromise: Promise<void> | null = null;

function syncStateFromLocalStorage(): { role: UserRole | null; userId: string | null; tenantId: string | null } {
  const role = localStorage.getItem(USER_ROLE_KEY) as UserRole | null;
  const userId = localStorage.getItem(USER_ID_KEY);
  const tenantId = localStorage.getItem(TENANT_ID_KEY);
  return {
    role: role || null,
    userId: userId || null,
    tenantId: tenantId || null,
  };
}

function writeLocalStorage(payload: { role: UserRole; userId?: string | null; tenantId?: string | null }) {
  localStorage.setItem(USER_ROLE_KEY, payload.role);
  if (payload.userId !== undefined && payload.userId !== null) {
    localStorage.setItem(USER_ID_KEY, payload.userId);
  } else {
    localStorage.removeItem(USER_ID_KEY);
  }
  if (payload.tenantId !== undefined && payload.tenantId !== null && payload.tenantId !== '') {
    localStorage.setItem(TENANT_ID_KEY, payload.tenantId);
  } else {
    localStorage.removeItem(TENANT_ID_KEY);
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [userRole, setUserRoleState] = useState<UserRole | null>(null);
  const [userId, setUserIdState] = useState<string | null>(null);
  const [tenantId, setTenantIdState] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const bootstrappedRef = useRef(false);

  const checkAuth = useCallback(async () => {
    const run = async (): Promise<void> => {
      const stored = syncStateFromLocalStorage();

      if (stored.role) {
        setUserRoleState(stored.role);
        setUserIdState(stored.userId);
        setTenantIdState(stored.tenantId);
        setIsLoading(false);

        const verifyInDev = import.meta.env.VITE_VERIFY_COOKIE_AUTH_IN_DEV === 'true';
        if (verifyInDev) {
          try {
            const user = await apiClient.get<AuthMeResponse>('/api/auth/me');
            setUserRoleState(user.role);
            setUserIdState(user.user_id);
            setTenantIdState(user.tenant_id);
            writeLocalStorage({ role: user.role, userId: user.user_id, tenantId: user.tenant_id });
          } catch {
            // One attempt only; keep localStorage identity, no retry
          }
        }
        return;
      }

      try {
        const user = await apiClient.get<AuthMeResponse>('/api/auth/me');
        setUserRoleState(user.role);
        setUserIdState(user.user_id);
        setTenantIdState(user.tenant_id);
        writeLocalStorage({ role: user.role, userId: user.user_id, tenantId: user.tenant_id });
      } catch {
        setUserRoleState(null);
        setUserIdState(null);
        setTenantIdState(null);
        localStorage.removeItem(USER_ROLE_KEY);
        localStorage.removeItem(USER_ID_KEY);
        localStorage.removeItem(TENANT_ID_KEY);
        // No retry on any error (including 401/403)
      } finally {
        setIsLoading(false);
      }
    };

    if (checkAuthPromise) {
      await checkAuthPromise;
      return;
    }
    checkAuthPromise = run();
    await checkAuthPromise;
    checkAuthPromise = null;
  }, []);

  const setDevIdentity = useCallback((payload: SetDevIdentityPayload) => {
    const { role, userId: uid, tenantId: tid } = payload;
    setUserRoleState(role);
    setUserIdState(uid ?? null);
    setTenantIdState(tid && tid !== '' ? tid : null);
    writeLocalStorage({ role, userId: uid ?? undefined, tenantId: tid ?? undefined });
  }, []);

  const setUserRole = useCallback((role: UserRole) => {
    setUserRoleState(role);
    localStorage.setItem(USER_ROLE_KEY, role);
  }, []);

  const logout = useCallback(async () => {
    try {
      await apiClient.post('/api/auth/logout', {});
    } catch {
      // Ignore errors on logout
    }
    setUserRoleState(null);
    setUserIdState(null);
    setTenantIdState(null);
    localStorage.removeItem(USER_ROLE_KEY);
    localStorage.removeItem(USER_ID_KEY);
    localStorage.removeItem(TENANT_ID_KEY);
  }, []);

  useEffect(() => {
    if (bootstrappedRef.current) return;
    bootstrappedRef.current = true;
    checkAuth();
  }, [checkAuth]);

  const value: AuthContextValue = {
    userRole,
    userId,
    tenantId,
    isLoading,
    checkAuth,
    setDevIdentity,
    setUserRole,
    logout,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuthContext(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuthContext must be used within AuthProvider');
  }
  return ctx;
}
