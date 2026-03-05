/**
 * Central nav definition. Visibility is permission-driven only; modules only disable items.
 * AppSidebar maps NAV_ITEMS -> rendered items with no inline role/module logic outside this config.
 * Module keys must match backend (see config/moduleKeys.ts).
 */
import { CAPABILITIES, type PermissionKey } from './permissions';
import type { TermKey } from './terminology';
import { getModuleLabel } from './moduleKeys';

/** @deprecated Use getModuleLabel from config/moduleKeys instead */
export const MODULE_KEY_LABELS: Record<string, string> = Object.fromEntries(
  ['projects_crop_cycles', 'crop_ops', 'machinery', 'inventory', 'land', 'land_leases', 'labour', 'treasury_payments', 'treasury_advances', 'ar_sales', 'reports', 'settlements'].map((k) => [k, getModuleLabel(k)])
);

export type NavItem = {
  key: string;
  label: string;
  to: string;
  requiredPermission: PermissionKey;
  requiredModules?: string[];
  requiresPlatform?: boolean;
  requiresTenant?: boolean;
  /** For submenu parent */
  children?: NavItem[];
  submenuKey?: string;
};

export type NavGroup = {
  name: string;
  items: NavItem[];
};

type TermFn = (k: TermKey) => string;

/** Build NAV_ITEMS (groups + items). Labels use term() where applicable. */
export function getNavGroups(term: TermFn, showOrchards: boolean, showLivestock: boolean): NavGroup[] {
  const VIEW = CAPABILITIES.TENANT_VIEW_ALL_DATA;
  const USERS = CAPABILITIES.TENANT_USERS_MANAGE;
  const ROLES = CAPABILITIES.TENANT_ROLES_ASSIGN;
  const MODULES = CAPABILITIES.TENANT_MODULES_MANAGE;

  return [
    {
      name: term('navFarm'),
      items: [
        { key: 'farm-pulse', label: 'Farm Pulse', to: '/app/farm-pulse', requiredPermission: VIEW },
        { key: 'today', label: 'Today', to: '/app/today', requiredPermission: VIEW },
        { key: 'alerts', label: 'Alerts', to: '/app/alerts', requiredPermission: VIEW },
        { key: 'fields', label: term('navFields'), to: '/app/projects', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        { key: 'crop-ops', label: term('navWork'), to: '/app/crop-ops', requiredPermission: VIEW, requiredModules: ['crop_ops'] },
        { key: 'harvests', label: 'Harvests', to: '/app/harvests', requiredPermission: VIEW, requiredModules: ['crop_ops'] },
        { key: 'pending-review', label: term('pendingReview'), to: '/app/transactions', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        {
          key: 'machinery',
          label: 'Machinery',
          to: '/app/machinery/machines',
          requiredPermission: VIEW,
          requiredModules: ['machinery'],
          submenuKey: 'machinery',
          children: [
            { key: 'machinery-machines', label: 'Machines', to: '/app/machinery/machines', requiredPermission: VIEW, requiredModules: ['machinery'] },
            { key: 'machinery-work-logs', label: 'Work Logs', to: '/app/machinery/work-logs', requiredPermission: VIEW, requiredModules: ['machinery'] },
            { key: 'machinery-services', label: 'Services', to: '/app/machinery/services', requiredPermission: VIEW, requiredModules: ['machinery'] },
            { key: 'machinery-charges', label: 'Charges', to: '/app/machinery/charges', requiredPermission: VIEW, requiredModules: ['machinery'] },
            { key: 'machinery-maintenance', label: 'Maintenance', to: '/app/machinery/maintenance-jobs', requiredPermission: VIEW, requiredModules: ['machinery'] },
            { key: 'machinery-maintenance-setup', label: 'Maintenance Setup', to: '/app/machinery/maintenance-types', requiredPermission: VIEW, requiredModules: ['machinery'] },
            { key: 'machinery-rate-cards', label: 'Rate Cards', to: '/app/machinery/rate-cards', requiredPermission: VIEW, requiredModules: ['machinery'] },
          ],
        },
        { key: 'inventory', label: 'Inventory', to: '/app/inventory', requiredPermission: VIEW, requiredModules: ['inventory'] },
        { key: 'land', label: 'Land Parcels', to: '/app/land', requiredPermission: VIEW, requiredModules: ['land'] },
        { key: 'land-leases', label: 'Land Leases (Maqada)', to: '/app/land-leases', requiredPermission: MODULES, requiredModules: ['land_leases'] },
        { key: 'crop-cycles', label: 'Crop Cycles', to: '/app/crop-cycles', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        { key: 'production-units', label: 'Production Units', to: '/app/production-units', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        ...(showOrchards ? [{ key: 'orchards', label: 'Orchards', to: '/app/orchards', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] } as NavItem] : []),
        ...(showLivestock ? [{ key: 'livestock', label: 'Livestock', to: '/app/livestock', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] } as NavItem] : []),
        { key: 'allocations', label: 'Land Allocation', to: '/app/allocations', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
      ],
    },
    {
      name: term('navPeople'),
      items: [
        { key: 'labour', label: 'Labour', to: '/app/labour', requiredPermission: VIEW, requiredModules: ['labour'] },
        { key: 'parties', label: 'People & Partners', to: '/app/parties', requiredPermission: VIEW },
      ],
    },
    {
      name: term('navMoney'),
      items: [
        { key: 'sales', label: 'Sales & Money', to: '/app/sales', requiredPermission: VIEW, requiredModules: ['ar_sales'] },
        { key: 'payments', label: term('navPayReceive'), to: '/app/payments', requiredPermission: VIEW, requiredModules: ['treasury_payments'] },
        { key: 'advances', label: 'Advances', to: '/app/advances', requiredPermission: VIEW, requiredModules: ['treasury_advances'] },
      ],
    },
    {
      name: term('navAccounting'),
      items: [
        { key: 'dashboard', label: 'Accounting Overview', to: '/app/dashboard', requiredPermission: VIEW },
        { key: 'review-queue', label: term('reviewQueue'), to: '/app/review-queue', requiredPermission: VIEW },
        { key: 'account-balances', label: 'Account Balances', to: '/app/reports/account-balances', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'crop-profitability', label: 'Crop Profitability', to: '/app/reports/crop-profitability', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        { key: 'ar-ageing', label: term('arAgeing'), to: '/app/reports/ar-ageing', requiredPermission: VIEW, requiredModules: ['ar_sales'] },
        { key: 'bank-reconciliation', label: 'Bank Reconciliation', to: '/app/reports/bank-reconciliation', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'trial-balance', label: term('trialBalance'), to: '/app/reports/trial-balance', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'governance', label: 'Governance', to: '/app/governance', requiredPermission: VIEW },
        { key: 'profit-loss', label: term('profitAndLoss'), to: '/app/reports/profit-loss', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'balance-sheet', label: term('balanceSheet'), to: '/app/reports/balance-sheet', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'cashbook', label: 'Cashbook', to: '/app/reports/cashbook', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'project-pl', label: 'Project P&L', to: '/app/reports/project-pl', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'crop-cycle-pl', label: 'Crop Cycle P&L', to: '/app/reports/crop-cycle-pl', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'profitability-trend', label: 'Profitability Trend', to: '/app/reports/crop-profitability-trend', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        { key: 'machinery-profitability', label: 'Machinery Profitability', to: '/app/machinery/reports/profitability', requiredPermission: VIEW, requiredModules: ['machinery'] },
        { key: 'sales-margin', label: 'Sales Margin', to: '/app/reports/sales-margin', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'party-ledger', label: 'Party Ledger', to: '/app/reports/party-ledger', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'landlord-statement', label: 'Landlord Statement', to: '/app/reports/landlord-statement', requiredPermission: VIEW, requiredModules: ['land_leases'] },
        { key: 'party-summary', label: 'Party Summary', to: '/app/reports/party-summary', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'party-ageing', label: 'Party Ageing', to: '/app/reports/role-ageing', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'reconciliation-dashboard', label: 'Reconcile Accounts', to: '/app/reports/reconciliation-dashboard', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'general-ledger', label: term('generalLedger'), to: '/app/reports/general-ledger', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'journals', label: 'General Journal', to: '/app/accounting/journals', requiredPermission: VIEW, requiredModules: ['reports'] },
        { key: 'settlement-packs', label: 'Settlement Packs', to: '/app/settlement', requiredPermission: VIEW, requiredModules: ['settlements'] },
        { key: 'accounting-periods', label: 'Accounting Periods', to: '/app/accounting/periods', requiredPermission: VIEW, requiredModules: ['reports'] },
      ],
    },
    {
      name: term('navSettings'),
      items: [
        { key: 'farm-profile', label: 'Farm Profile', to: '/app/admin/farm', requiredPermission: MODULES },
        { key: 'users', label: 'Users', to: '/app/admin/users', requiredPermission: USERS },
        { key: 'roles', label: 'Roles', to: '/app/admin/roles', requiredPermission: ROLES },
        { key: 'audit-logs', label: 'Audit Logs', to: '/app/admin/audit-logs', requiredPermission: MODULES },
        { key: 'modules', label: 'Modules', to: '/app/admin/modules', requiredPermission: MODULES },
        { key: 'farm-integrity', label: 'Farm Integrity', to: '/app/internal/farm-integrity', requiredPermission: MODULES },
        { key: 'localisation', label: 'Localisation', to: '/app/settings/localisation', requiredPermission: MODULES },
      ],
    },
  ];
}
