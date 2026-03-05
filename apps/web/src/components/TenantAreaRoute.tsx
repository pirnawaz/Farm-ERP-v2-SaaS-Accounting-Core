import { useEffect } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { useImpersonationStatusForUi } from '../hooks/useImpersonation';

interface TenantAreaRouteProps {
  children: React.ReactNode;
}

/**
 * Restricts tenant area (/app/* but not /app/platform/*) to tenant users.
 * Platform admins (role platform_admin and no tenantId) are redirected to platform,
 * unless impersonating: then we allow through and sync tenantId from impersonation status.
 * If user must change password, redirect to /app/set-password except when already there.
 */
export function TenantAreaRoute({ children }: TenantAreaRouteProps) {
  const { userRole, tenantId, mustChangePassword, setTenantIdFromImpersonation, checkAuth } = useAuth();
  const location = useLocation();
  const { data: impersonationStatus, isLoading: statusLoading } = useImpersonationStatusForUi(true);

  const isPlatformAdminWithoutTenant = userRole === 'platform_admin' && !tenantId;
  const isImpersonating = Boolean(impersonationStatus?.is_impersonating && impersonationStatus?.tenant);

  const tenantRoles = ['tenant_admin', 'accountant', 'operator'];
  const isTenantRole = userRole != null && tenantRoles.includes(userRole);
  if (isTenantRole && !tenantId) {
    return <Navigate to="/login" replace state={{ needsTenantSelection: true }} />;
  }

  useEffect(() => {
    if (isPlatformAdminWithoutTenant && isImpersonating && impersonationStatus?.tenant?.id) {
      setTenantIdFromImpersonation(impersonationStatus.tenant.id);
      checkAuth();
    }
  }, [isPlatformAdminWithoutTenant, isImpersonating, impersonationStatus?.tenant?.id, setTenantIdFromImpersonation, checkAuth]);

  if (userRole === 'platform_admin' && !tenantId) {
    if (statusLoading) {
      return (
        <div className="flex items-center justify-center min-h-[200px] text-gray-500">
          Loading…
        </div>
      );
    }
    if (!isImpersonating) {
      return <Navigate to="/app/platform/tenants" replace />;
    }
  }

  if (mustChangePassword && !location.pathname.endsWith('/set-password')) {
    return <Navigate to="/app/set-password" replace />;
  }

  return <>{children}</>;
}
