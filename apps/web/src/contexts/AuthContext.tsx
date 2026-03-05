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
import { platformApi } from '../api/platform';
import type { UserRole } from '../types';

const USER_ROLE_KEY = 'farm_erp_user_role';
const USER_ID_KEY = 'farm_erp_user_id';
const TENANT_ID_KEY = 'farm_erp_tenant_id';
const AUTH_MODE_KEY = 'farm_erp_auth_mode';

type AuthUser = { id: string; name: string; email: string; role: string; must_change_password?: boolean };
type AuthTenant = { id: string; name: string; slug?: string } | null;
type AuthMeResponse = { user: AuthUser; tenant: AuthTenant };

export type AuthMode = 'platform' | 'tenant';

export type SetDevIdentityPayload = {
  role: UserRole;
  userId?: string | null;
  tenantId?: string | null;
  mustChangePassword?: boolean;
};

/** Payload from unified login (platform or tenant or select_tenant). */
export type SetIdentityFromUnifiedLoginPayload = {
  mode: 'platform' | 'tenant';
  userId: string;
  role: UserRole;
  tenantId?: string | null;
  mustChangePassword?: boolean;
};

type AuthContextValue = {
  userRole: UserRole | null;
  userId: string | null;
  tenantId: string | null;
  mode: AuthMode | null;
  mustChangePassword: boolean;
  isLoading: boolean;
  checkAuth: () => Promise<void>;
  setDevIdentity: (payload: SetDevIdentityPayload) => void;
  /** Set state from unified login or select-tenant response. */
  setIdentityFromUnifiedLogin: (payload: SetIdentityFromUnifiedLoginPayload) => void;
  setUserRole: (role: UserRole) => void;
  setMustChangePassword: (value: boolean) => void;
  setTenantIdFromImpersonation: (tenantId: string) => void;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

/** Dedupe in-flight checkAuth so only one runs at a time. */
let checkAuthPromise: Promise<void> | null = null;

const MUST_CHANGE_PASSWORD_KEY = 'farm_erp_must_change_password';

function syncStateFromLocalStorage(): {
  role: UserRole | null;
  userId: string | null;
  tenantId: string | null;
  mode: AuthMode | null;
  mustChangePassword: boolean;
} {
  const role = localStorage.getItem(USER_ROLE_KEY) as UserRole | null;
  const userId = localStorage.getItem(USER_ID_KEY);
  const tenantId = localStorage.getItem(TENANT_ID_KEY);
  const mode = localStorage.getItem(AUTH_MODE_KEY) as AuthMode | null;
  const mustChangePassword = localStorage.getItem(MUST_CHANGE_PASSWORD_KEY) === 'true';
  return {
    role: role || null,
    userId: userId || null,
    tenantId: tenantId || null,
    mode: mode || null,
    mustChangePassword,
  };
}

function writeLocalStorage(payload: {
  role: UserRole;
  userId?: string | null;
  tenantId?: string | null;
  mode?: AuthMode | null;
}) {
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
  if (payload.mode !== undefined && payload.mode !== null) {
    localStorage.setItem(AUTH_MODE_KEY, payload.mode);
  } else {
    localStorage.removeItem(AUTH_MODE_KEY);
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [userRole, setUserRoleState] = useState<UserRole | null>(null);
  const [userId, setUserIdState] = useState<string | null>(null);
  const [tenantId, setTenantIdState] = useState<string | null>(null);
  const [mode, setModeState] = useState<AuthMode | null>(null);
  const [mustChangePassword, setMustChangePasswordState] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const bootstrappedRef = useRef(false);

  const checkAuth = useCallback(async () => {
    const run = async (): Promise<void> => {
      const stored = syncStateFromLocalStorage();

      if (stored.role) {
        setUserRoleState(stored.role);
        setUserIdState(stored.userId);
        setTenantIdState(stored.tenantId);
        setModeState(stored.mode);
        setMustChangePasswordState(stored.mustChangePassword);
        setIsLoading(false);

        const isPlatformAdminSession =
          stored.mode === 'platform' || (stored.role === 'platform_admin' && !stored.tenantId);
        if (isPlatformAdminSession) {
          try {
            const res = await platformApi.me();
            setUserRoleState('platform_admin');
            setUserIdState(res.user.id);
            setTenantIdState(null);
            setModeState('platform');
            writeLocalStorage({
              role: 'platform_admin',
              userId: res.user.id,
              tenantId: null,
              mode: 'platform',
            });
          } catch {
            setUserRoleState(null);
            setUserIdState(null);
            setTenantIdState(null);
            localStorage.removeItem(USER_ROLE_KEY);
            localStorage.removeItem(USER_ID_KEY);
            localStorage.removeItem(TENANT_ID_KEY);
          }
          setIsLoading(false);
          return;
        }

        const verifyInDev = import.meta.env.VITE_VERIFY_COOKIE_AUTH_IN_DEV === 'true';
        if (verifyInDev) {
          try {
            const res = await apiClient.get<AuthMeResponse>('/api/auth/me');
            const role = res.user.role as UserRole;
            const tid = res.tenant?.id ?? null;
            const mustChange = Boolean(res.user.must_change_password);
            setUserRoleState(role);
            setUserIdState(res.user.id);
            setTenantIdState(tid);
            setMustChangePasswordState(mustChange);
            writeLocalStorage({
              role,
              userId: res.user.id,
              tenantId: tid,
              mode: tid ? 'tenant' : 'platform',
            });
            if (mustChange) {
              localStorage.setItem(MUST_CHANGE_PASSWORD_KEY, 'true');
            } else {
              localStorage.removeItem(MUST_CHANGE_PASSWORD_KEY);
            }
          } catch {
            // One attempt only; keep localStorage identity, no retry
          }
        }
        return;
      }

      try {
        const res = await apiClient.get<AuthMeResponse>('/api/auth/me');
        const role = res.user.role as UserRole;
        const tid = res.tenant?.id ?? null;
        const mustChange = Boolean(res.user.must_change_password);
        setUserRoleState(role);
        setUserIdState(res.user.id);
        setTenantIdState(tid);
        setModeState(tid ? 'tenant' : 'platform');
        setMustChangePasswordState(mustChange);
        writeLocalStorage({
          role,
          userId: res.user.id,
          tenantId: tid,
          mode: tid ? 'tenant' : 'platform',
        });
        if (mustChange) {
          localStorage.setItem(MUST_CHANGE_PASSWORD_KEY, 'true');
        } else {
          localStorage.removeItem(MUST_CHANGE_PASSWORD_KEY);
        }
      } catch {
        setUserRoleState(null);
        setUserIdState(null);
        setTenantIdState(null);
        setModeState(null);
        localStorage.removeItem(USER_ROLE_KEY);
        localStorage.removeItem(USER_ID_KEY);
        localStorage.removeItem(TENANT_ID_KEY);
        localStorage.removeItem(AUTH_MODE_KEY);
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
    const { role, userId: uid, tenantId: tid, mustChangePassword: mcp } = payload;
    setUserRoleState(role);
    setUserIdState(uid ?? null);
    setTenantIdState(tid && tid !== '' ? tid : null);
    setModeState(tid ? 'tenant' : 'platform');
    const mustChange = mcp === true;
    setMustChangePasswordState(mustChange);
    writeLocalStorage({
      role,
      userId: uid ?? undefined,
      tenantId: tid ?? undefined,
      mode: tid ? 'tenant' : 'platform',
    });
    if (mustChange) {
      localStorage.setItem(MUST_CHANGE_PASSWORD_KEY, 'true');
    } else {
      localStorage.removeItem(MUST_CHANGE_PASSWORD_KEY);
    }
  }, []);

  const setIdentityFromUnifiedLogin = useCallback((payload: SetIdentityFromUnifiedLoginPayload) => {
    const { mode: m, userId: uid, role, tenantId: tid, mustChangePassword: mcp } = payload;
    setUserRoleState(role);
    setUserIdState(uid);
    setTenantIdState(tid && tid !== '' ? tid : null);
    setModeState(m);
    const mustChange = mcp === true;
    setMustChangePasswordState(mustChange);
    writeLocalStorage({
      role,
      userId: uid,
      tenantId: tid ?? undefined,
      mode: m,
    });
    if (mustChange) {
      localStorage.setItem(MUST_CHANGE_PASSWORD_KEY, 'true');
    } else {
      localStorage.removeItem(MUST_CHANGE_PASSWORD_KEY);
    }
  }, []);

  const setUserRole = useCallback((role: UserRole) => {
    setUserRoleState(role);
    localStorage.setItem(USER_ROLE_KEY, role);
  }, []);

  const setMustChangePassword = useCallback((value: boolean) => {
    setMustChangePasswordState(value);
    if (value) {
      localStorage.setItem(MUST_CHANGE_PASSWORD_KEY, 'true');
    } else {
      localStorage.removeItem(MUST_CHANGE_PASSWORD_KEY);
    }
  }, []);

  const setTenantIdFromImpersonation = useCallback((tid: string) => {
    setTenantIdState(tid);
    localStorage.setItem(TENANT_ID_KEY, tid);
  }, []);

  const logout = useCallback(async () => {
    const isPlatformSession = mode === 'platform' || (userRole === 'platform_admin' && !tenantId);
    try {
      if (isPlatformSession) {
        await platformApi.logout();
      } else {
        await apiClient.post('/api/auth/logout', {});
      }
    } catch {
      // Ignore errors on logout
    }
    setUserRoleState(null);
    setUserIdState(null);
    setTenantIdState(null);
    setModeState(null);
    setMustChangePasswordState(false);
    localStorage.removeItem(USER_ROLE_KEY);
    localStorage.removeItem(USER_ID_KEY);
    localStorage.removeItem(TENANT_ID_KEY);
    localStorage.removeItem(AUTH_MODE_KEY);
    localStorage.removeItem(MUST_CHANGE_PASSWORD_KEY);
  }, [userRole, tenantId, mode]);

  useEffect(() => {
    if (bootstrappedRef.current) return;
    bootstrappedRef.current = true;
    checkAuth();
  }, [checkAuth]);

  const value: AuthContextValue = {
    userRole,
    userId,
    tenantId,
    mode,
    mustChangePassword,
    isLoading,
    checkAuth,
    setDevIdentity,
    setIdentityFromUnifiedLogin,
    setUserRole,
    setMustChangePassword,
    setTenantIdFromImpersonation,
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
