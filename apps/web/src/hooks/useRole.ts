import { useAuth } from './useAuth';
import type { UserRole } from '../types';

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
  };
}
