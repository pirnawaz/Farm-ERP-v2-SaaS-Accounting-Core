/**
 * Single source of truth for role permissions.
 * Backend enforces via route middleware; this drives UI visibility and the Role Permissions Matrix.
 */
import type { UserRole } from '../types';

/** Permission keys (capabilities). */
export const CAPABILITIES = {
  // Platform scope (only platform_admin)
  PLATFORM_MANAGE_TENANTS: 'platform.manage_tenants',
  PLATFORM_VIEW_ALL_TENANTS: 'platform.view_all_tenants',
  PLATFORM_MODULES_PER_TENANT_MANAGE: 'platform.modules_per_tenant_manage',

  // Tenant scope
  TENANT_USERS_MANAGE: 'tenant.users_manage',
  TENANT_ROLES_ASSIGN: 'tenant.roles_assign',
  TENANT_MODULES_MANAGE: 'tenant.modules_manage',
  TENANT_CYCLES_MANAGE: 'tenant.cycles_manage',
  TENANT_TRANSACTIONS_CREATE_EDIT: 'tenant.transactions_create_edit',
  TENANT_TRANSACTIONS_CREATE_EDIT_OWN: 'tenant.transactions_create_edit_own',
  TENANT_POST_TO_ACCOUNTS: 'tenant.post_to_accounts',
  TENANT_REVERSE_POSTING: 'tenant.reverse_posting',
  TENANT_VIEW_ALL_DATA: 'tenant.view_all_data',
  TENANT_SETTLEMENTS_MANAGE: 'tenant.settlements_manage',
} as const;

export type PermissionKey = (typeof CAPABILITIES)[keyof typeof CAPABILITIES];

/** Human-readable labels for the Role Permissions Matrix. */
export const PERMISSION_LABELS: Record<PermissionKey, string> = {
  [CAPABILITIES.PLATFORM_MANAGE_TENANTS]: 'Manage tenants',
  [CAPABILITIES.PLATFORM_VIEW_ALL_TENANTS]: 'View all tenants',
  [CAPABILITIES.PLATFORM_MODULES_PER_TENANT_MANAGE]: 'Enable/disable modules per tenant',
  [CAPABILITIES.TENANT_USERS_MANAGE]: 'Manage users',
  [CAPABILITIES.TENANT_ROLES_ASSIGN]: 'Assign roles',
  [CAPABILITIES.TENANT_MODULES_MANAGE]: 'Enable/disable modules (tenant)',
  [CAPABILITIES.TENANT_CYCLES_MANAGE]: 'Close/open crop cycles',
  [CAPABILITIES.TENANT_TRANSACTIONS_CREATE_EDIT]: 'Create/edit transactions',
  [CAPABILITIES.TENANT_TRANSACTIONS_CREATE_EDIT_OWN]: 'Create/edit own transactions',
  [CAPABILITIES.TENANT_POST_TO_ACCOUNTS]: 'Post to accounts',
  [CAPABILITIES.TENANT_REVERSE_POSTING]: 'Reverse posting',
  [CAPABILITIES.TENANT_VIEW_ALL_DATA]: 'View all data',
  [CAPABILITIES.TENANT_SETTLEMENTS_MANAGE]: 'Manage share rules & settlements',
};

/** All permission keys in display order. */
export const ALL_PERMISSION_KEYS: PermissionKey[] = [
  CAPABILITIES.PLATFORM_MANAGE_TENANTS,
  CAPABILITIES.PLATFORM_VIEW_ALL_TENANTS,
  CAPABILITIES.PLATFORM_MODULES_PER_TENANT_MANAGE,
  CAPABILITIES.TENANT_USERS_MANAGE,
  CAPABILITIES.TENANT_ROLES_ASSIGN,
  CAPABILITIES.TENANT_MODULES_MANAGE,
  CAPABILITIES.TENANT_CYCLES_MANAGE,
  CAPABILITIES.TENANT_TRANSACTIONS_CREATE_EDIT,
  CAPABILITIES.TENANT_TRANSACTIONS_CREATE_EDIT_OWN,
  CAPABILITIES.TENANT_POST_TO_ACCOUNTS,
  CAPABILITIES.TENANT_REVERSE_POSTING,
  CAPABILITIES.TENANT_VIEW_ALL_DATA,
  CAPABILITIES.TENANT_SETTLEMENTS_MANAGE,
];

/** Role -> set of permission keys. platform_admin has all. */
const ROLE_PERMISSIONS: Record<UserRole, Set<PermissionKey>> = {
  platform_admin: new Set(ALL_PERMISSION_KEYS),
  tenant_admin: new Set([
    CAPABILITIES.TENANT_USERS_MANAGE,
    CAPABILITIES.TENANT_ROLES_ASSIGN,
    CAPABILITIES.TENANT_MODULES_MANAGE,
    CAPABILITIES.TENANT_CYCLES_MANAGE,
    CAPABILITIES.TENANT_TRANSACTIONS_CREATE_EDIT,
    CAPABILITIES.TENANT_TRANSACTIONS_CREATE_EDIT_OWN,
    CAPABILITIES.TENANT_POST_TO_ACCOUNTS,
    CAPABILITIES.TENANT_REVERSE_POSTING,
    CAPABILITIES.TENANT_VIEW_ALL_DATA,
    CAPABILITIES.TENANT_SETTLEMENTS_MANAGE,
  ]),
  accountant: new Set([
    CAPABILITIES.TENANT_TRANSACTIONS_CREATE_EDIT,
    CAPABILITIES.TENANT_POST_TO_ACCOUNTS,
    CAPABILITIES.TENANT_REVERSE_POSTING,
    CAPABILITIES.TENANT_VIEW_ALL_DATA,
    CAPABILITIES.TENANT_SETTLEMENTS_MANAGE,
  ]),
  operator: new Set([
    CAPABILITIES.TENANT_TRANSACTIONS_CREATE_EDIT_OWN,
    CAPABILITIES.TENANT_VIEW_ALL_DATA,
  ]),
};

/**
 * Returns whether a role has a given permission.
 */
export function can(role: UserRole | null | undefined, permission: PermissionKey): boolean {
  if (!role) return false;
  return ROLE_PERMISSIONS[role]?.has(permission) ?? false;
}

/**
 * Returns the set of permissions for a role (for matrix display).
 */
export function getPermissionsForRole(role: UserRole): Set<PermissionKey> {
  return ROLE_PERMISSIONS[role] ?? new Set();
}

export { PERMISSION_LABELS as permissionLabels, ROLE_PERMISSIONS as rolePermissions };
