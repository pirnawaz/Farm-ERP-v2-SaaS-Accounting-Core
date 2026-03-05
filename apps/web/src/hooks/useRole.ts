import { useAuth } from './useAuth';
import type { UserRole } from '../types';
import { can as canPermission, type PermissionKey } from '../config/permissions';

export function useRole() {
  const { userRole } = useAuth();

  const isTenantAdmin = userRole === 'tenant_admin';
  const isAccountant = userRole === 'accountant';
  const isOperator = userRole === 'operator';

  const canPost = isTenantAdmin || isAccountant;
  const canSettle = isTenantAdmin || isAccountant;
  const canManageUsers = isTenantAdmin;
  const canCloseCropCycle = isTenantAdmin;

  const hasRole = (role: UserRole | readonly UserRole[]) => {
    if (!userRole) return false;
    if (Array.isArray(role)) {
      return role.includes(userRole);
    }
    return userRole === role;
  };

  /** Check permission by capability key (uses shared role→permission mapping). */
  const can = (permission: PermissionKey) => canPermission(userRole ?? undefined, permission);

  return {
    userRole,
    isTenantAdmin,
    isAccountant,
    isOperator,
    canPost,
    canSettle,
    canManageUsers,
    canCloseCropCycle,
    hasRole,
    can,
  };
}
