/**
 * Domain-based tenant navigation (domain → section → item).
 *
 * - **Primary API:** `getNavDomains()` — use this for the sidebar and any IA that mirrors it.
 * - **Deprecated:** `getNavGroups()` — flattens domains for legacy callers only; do not use for new UI.
 * - Permission keys drive which items exist after `filterDomainsByPermission`; modules only disable (grey) items.
 * - Module keys must match backend (see `moduleKeys.ts`).
 *
 * **React usage:** When filtering by permission in `useMemo`, depend on a stable value such as `userRole`
 * (from auth) and call `can(role, permission)` from `permissions.ts` — not the `can` function from `useRole()`,
 * which is a new function reference every render and will break memoization.
 */
import { CAPABILITIES, type PermissionKey } from './permissions';
import type { TermKey } from './terminology';
import { getModuleLabel } from './moduleKeys';

/** @deprecated Use getModuleLabel from config/moduleKeys instead */
export const MODULE_KEY_LABELS: Record<string, string> = Object.fromEntries(
  [
    'projects_crop_cycles',
    'crop_ops',
    'machinery',
    'inventory',
    'land',
    'land_leases',
    'labour',
    'treasury_payments',
    'treasury_advances',
    'ar_sales',
    'reports',
    'settlements',
  ].map((k) => [k, getModuleLabel(k)]),
);

export type NavItem = {
  key: string;
  label: string;
  to: string;
  requiredPermission: PermissionKey;
  requiredModules?: string[];
  requiresPlatform?: boolean;
  requiresTenant?: boolean;
  children?: NavItem[];
  submenuKey?: string;
};

export type NavSection = {
  sectionKey: string;
  /** Optional heading under a domain (e.g. "Land & Crops"). Null = no sub-heading. */
  sectionTitle: string | null;
  items: NavItem[];
};

export type NavDomain = {
  domainKey: string;
  name: string;
  sections: NavSection[];
};

/**
 * Role-based sidebar pruning (UX only).
 *
 * - This is NOT a security boundary; backend + route guards remain the source of truth.
 * - Pruning is applied in addition to permission filtering (it can only hide items).
 */
export function pruneDomainsForRole(domains: NavDomain[], userRole: string | null | undefined): NavDomain[] {
  // Tenant admins get the full sidebar (subject to permission + module gating in UI).
  if (userRole === 'tenant_admin') return domains;

  const role = userRole ?? '';

  const accountantAllowItemKeys = new Set<string>([
    // Farm
    'farm-pulse',
    'today',
    'alerts',

    // Operations context (accounting-relevant, mostly read-oriented)
    'fields',
    'land',
    'crop-cycles',
    'production-units',
    'land-leases',
    'harvests',
    // Stock overview is useful for valuation / GRN/issue context.
    'inventory',
    // Labour and people balances often drive accruals and payments.
    'labour',
    'parties',
    // Draft entries are useful context for accounting review.
    'pending-review',

    // Governance (audit/control)
    'farm-integrity',
    'audit-logs',
  ]);

  const operatorAllowItemKeys = new Set<string>([
    // Farm
    'farm-pulse',
    'today',
    'alerts',

    // Operations (day-to-day)
    'fields',
    'land',
    'crop-cycles',
    'production-units',
    'allocations',
    'land-leases',
    'orchards',
    'livestock',
    'crop-ops',
    'harvests',
    'pending-review',
    'machinery-machines',
    'machinery-work-logs',
    'machinery-services',
    'machinery-charges',
    'machinery-maintenance',
    'machinery-maintenance-setup',
    'machinery-rate-cards',
    'inventory',
    'labour',
    'parties',

    // Limited finance (operationally necessary)
    'payments',
    'advances',
  ]);

  const keepAllFinance = role === 'accountant';
  const keepDomainsByRole: Record<string, Set<string>> = {
    accountant: new Set(['farm', 'operations', 'finance', 'governance']),
    operator: new Set(['farm', 'operations', 'finance']),
  };

  const allowedDomains = keepDomainsByRole[role];
  if (!allowedDomains) {
    // Unknown roles: do not prune.
    return domains;
  }

  const allowedItemKeys = role === 'accountant' ? accountantAllowItemKeys : operatorAllowItemKeys;

  const pruneItems = (items: NavItem[]): NavItem[] =>
    items
      .map((item) => {
        if (isSubmenuParent(item)) {
          const kids = item.children.filter((c) => allowedItemKeys.has(c.key));
          return kids.length ? { ...item, children: kids } : null;
        }
        return allowedItemKeys.has(item.key) ? item : null;
      })
      .filter((x): x is NavItem => x !== null);

  return domains
    .filter((d) => allowedDomains.has(d.domainKey))
    .map((d) => ({
      ...d,
      sections: d.sections
        .map((s) => {
          // For accountants we keep all Finance sections as-is (still permission-filtered later).
          if (keepAllFinance && d.domainKey === 'finance') return s;
          return { ...s, items: pruneItems(s.items) };
        })
        .filter((s) => s.items.length > 0),
    }))
    .filter((d) => d.sections.length > 0);
}

/** @deprecated Legacy flat group shape; use NavDomain + getNavDomains. */
export type NavGroup = {
  name: string;
  items: NavItem[];
};

type TermFn = (k: TermKey) => string;

export function isSubmenuParent(item: NavItem): item is NavItem & { children: NavItem[]; submenuKey: string } {
  return Array.isArray(item.children) && item.children.length > 0 && !!item.submenuKey;
}

type CanFn = (permission: PermissionKey) => boolean;

function filterNavItems(items: NavItem[], can: CanFn): NavItem[] {
  return items
    .map((item) => {
      if (isSubmenuParent(item)) {
        const visibleChildren = item.children.filter((c) => can(c.requiredPermission));
        if (visibleChildren.length === 0) {
          return null;
        }
        return { ...item, children: visibleChildren };
      }
      return can(item.requiredPermission) ? item : null;
    })
    .filter((item): item is NavItem => item !== null);
}

/** Drop empty sections/domains after permission filtering (same semantics as pre-refactor sidebar). */
export function filterDomainsByPermission(domains: NavDomain[], can: CanFn): NavDomain[] {
  return domains
    .map((d) => ({
      ...d,
      sections: d.sections
        .map((s) => ({
          ...s,
          items: filterNavItems(s.items, can),
        }))
        .filter((s) => s.items.length > 0),
    }))
    .filter((d) => d.sections.length > 0);
}

function financeSectionItems(term: TermFn, VIEW: PermissionKey): {
  moneyTreasury: NavItem[];
  accounting: NavItem[];
  reports: NavItem[];
  analysis: NavItem[];
  receivables: NavItem[];
  reconciliation: NavItem[];
  governance: NavItem[];
} {
  return {
    moneyTreasury: [
      { key: 'sales', label: 'Sales & Money', to: '/app/sales', requiredPermission: VIEW, requiredModules: ['ar_sales'] },
      { key: 'payments', label: term('navPayReceive'), to: '/app/payments', requiredPermission: VIEW, requiredModules: ['treasury_payments'] },
      { key: 'advances', label: 'Advances', to: '/app/advances', requiredPermission: VIEW, requiredModules: ['treasury_advances'] },
    ],
    accounting: [
      { key: 'dashboard', label: 'Accounting Overview', to: '/app/dashboard', requiredPermission: VIEW },
      { key: 'journals', label: 'General Journal', to: '/app/accounting/journals', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'accounting-periods', label: 'Accounting Periods', to: '/app/accounting/periods', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'general-ledger', label: term('generalLedger'), to: '/app/reports/general-ledger', requiredPermission: VIEW, requiredModules: ['reports'] },
    ],
    reports: [
      { key: 'account-balances', label: 'Account Balances', to: '/app/reports/account-balances', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'trial-balance', label: term('trialBalance'), to: '/app/reports/trial-balance', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'profit-loss', label: term('profitAndLoss'), to: '/app/reports/profit-loss', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'balance-sheet', label: term('balanceSheet'), to: '/app/reports/balance-sheet', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'cashbook', label: 'Cashbook', to: '/app/reports/cashbook', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'party-ledger', label: 'Party Ledger', to: '/app/reports/party-ledger', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'party-summary', label: 'Party Summary', to: '/app/reports/party-summary', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'party-ageing', label: 'Party Ageing', to: '/app/reports/role-ageing', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'landlord-statement', label: 'Landlord Statement', to: '/app/reports/landlord-statement', requiredPermission: VIEW, requiredModules: ['land_leases'] },
      { key: 'settlement-packs', label: 'Settlement Packs', to: '/app/settlement', requiredPermission: VIEW, requiredModules: ['settlements'] },
    ],
    analysis: [
      { key: 'crop-profitability', label: 'Crop Profitability', to: '/app/reports/crop-profitability', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
      { key: 'project-pl', label: 'Field Cycle P&L', to: '/app/reports/project-pl', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'crop-cycle-pl', label: 'Crop Cycle P&L', to: '/app/reports/crop-cycle-pl', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'profitability-trend', label: 'Profitability Trend', to: '/app/reports/crop-profitability-trend', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
      { key: 'sales-margin', label: 'Sales Margin', to: '/app/reports/sales-margin', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'machinery-profitability', label: 'Machinery Profitability', to: '/app/machinery/reports/profitability', requiredPermission: VIEW, requiredModules: ['machinery'] },
    ],
    receivables: [{ key: 'ar-ageing', label: term('arAgeing'), to: '/app/reports/ar-ageing', requiredPermission: VIEW, requiredModules: ['ar_sales'] }],
    reconciliation: [
      { key: 'bank-reconciliation', label: 'Bank Reconciliation', to: '/app/reports/bank-reconciliation', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'reconciliation-dashboard', label: 'Reconcile Accounts', to: '/app/reports/reconciliation-dashboard', requiredPermission: VIEW, requiredModules: ['reports'] },
    ],
    governance: [{ key: 'review-queue', label: term('reviewQueue'), to: '/app/review-queue', requiredPermission: VIEW }],
  };
}

/**
 * Tenant sidebar: top-level domains (Farm, Operations, Finance, Governance, Settings)
 * with optional section headings under Operations and Finance.
 */
export function getNavDomains(term: TermFn, showOrchards: boolean, showLivestock: boolean): NavDomain[] {
  const VIEW = CAPABILITIES.TENANT_VIEW_ALL_DATA;
  const USERS = CAPABILITIES.TENANT_USERS_MANAGE;
  const ROLES = CAPABILITIES.TENANT_ROLES_ASSIGN;
  const MODULES = CAPABILITIES.TENANT_MODULES_MANAGE;

  const machineryItems: NavItem[] = [
    { key: 'machinery-machines', label: 'Machines', to: '/app/machinery/machines', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-work-logs', label: 'Machine Work Logs', to: '/app/machinery/work-logs', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-services', label: 'Services', to: '/app/machinery/services', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-charges', label: 'Charges', to: '/app/machinery/charges', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-maintenance', label: 'Maintenance', to: '/app/machinery/maintenance-jobs', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-maintenance-setup', label: 'Maintenance Setup', to: '/app/machinery/maintenance-types', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-rate-cards', label: 'Rate Cards', to: '/app/machinery/rate-cards', requiredPermission: VIEW, requiredModules: ['machinery'] },
  ];

  const landAndCrops: NavItem[] = [
    { key: 'fields', label: term('navFields'), to: '/app/projects', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
    { key: 'land', label: 'Land Parcels', to: '/app/land', requiredPermission: VIEW, requiredModules: ['land'] },
    { key: 'crop-cycles', label: 'Crop Cycles', to: '/app/crop-cycles', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
    { key: 'production-units', label: 'Production Units', to: '/app/production-units', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
    { key: 'allocations', label: 'Land Allocation', to: '/app/allocations', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
    { key: 'land-leases', label: 'Land Leases (Maqada)', to: '/app/land-leases', requiredPermission: MODULES, requiredModules: ['land_leases'] },
    ...(showOrchards ? [{ key: 'orchards', label: 'Orchards', to: '/app/orchards', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] } as NavItem] : []),
    ...(showLivestock ? [{ key: 'livestock', label: 'Livestock', to: '/app/livestock', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] } as NavItem] : []),
  ];

  const workAndHarvest: NavItem[] = [
    { key: 'crop-ops', label: 'Work Logs', to: '/app/crop-ops', requiredPermission: VIEW, requiredModules: ['crop_ops'] },
    { key: 'harvests', label: 'Harvests', to: '/app/harvests', requiredPermission: VIEW, requiredModules: ['crop_ops'] },
    { key: 'pending-review', label: 'Draft Entries', to: '/app/transactions', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
  ];

  return [
    {
      domainKey: 'farm',
      name: term('navDomainFarm'),
      sections: [
        {
          sectionKey: 'farm-main',
          sectionTitle: null,
          items: [
            { key: 'farm-pulse', label: 'Farm Pulse', to: '/app/farm-pulse', requiredPermission: VIEW },
            { key: 'today', label: 'Today', to: '/app/today', requiredPermission: VIEW },
            { key: 'alerts', label: 'Alerts', to: '/app/alerts', requiredPermission: VIEW },
          ],
        },
      ],
    },
    {
      domainKey: 'operations',
      name: term('navDomainOperations'),
      sections: [
        { sectionKey: 'ops-land-crops', sectionTitle: 'Land & Crops', items: landAndCrops },
        { sectionKey: 'ops-work-harvest', sectionTitle: 'Work & Harvest', items: workAndHarvest },
        { sectionKey: 'ops-machinery', sectionTitle: 'Machinery', items: machineryItems },
        {
          sectionKey: 'ops-inventory',
          sectionTitle: 'Inventory',
          items: [{ key: 'inventory', label: 'Stock Overview', to: '/app/inventory', requiredPermission: VIEW, requiredModules: ['inventory'] }],
        },
        {
          sectionKey: 'ops-people',
          sectionTitle: 'People',
          items: [
            { key: 'labour', label: 'Labour', to: '/app/labour', requiredPermission: VIEW, requiredModules: ['labour'] },
            { key: 'parties', label: 'People & Partners', to: '/app/parties', requiredPermission: VIEW },
          ],
        },
      ],
    },
    {
      domainKey: 'finance',
      name: term('navDomainFinance'),
      sections: [
        {
          sectionKey: 'fin-money-treasury',
          sectionTitle: 'Money & Treasury',
          items: financeSectionItems(term, VIEW).moneyTreasury,
        },
        {
          sectionKey: 'fin-accounting',
          sectionTitle: 'Accounting',
          items: financeSectionItems(term, VIEW).accounting,
        },
        {
          sectionKey: 'fin-reports',
          sectionTitle: 'Reports',
          items: financeSectionItems(term, VIEW).reports,
        },
        {
          sectionKey: 'fin-analysis',
          sectionTitle: 'Analysis',
          items: financeSectionItems(term, VIEW).analysis,
        },
        {
          sectionKey: 'fin-receivables',
          sectionTitle: 'Receivables',
          items: financeSectionItems(term, VIEW).receivables,
        },
        {
          sectionKey: 'fin-reconciliation',
          sectionTitle: 'Reconciliation',
          items: financeSectionItems(term, VIEW).reconciliation,
        },
        {
          sectionKey: 'fin-governance',
          sectionTitle: 'Governance',
          items: financeSectionItems(term, VIEW).governance,
        },
      ],
    },
    {
      domainKey: 'governance',
      name: term('navDomainGovernance'),
      sections: [
        {
          sectionKey: 'gov-main',
          sectionTitle: null,
          items: [
            { key: 'farm-integrity', label: 'Farm Integrity', to: '/app/internal/farm-integrity', requiredPermission: MODULES },
            { key: 'audit-logs', label: 'Audit Logs', to: '/app/admin/audit-logs', requiredPermission: MODULES },
          ],
        },
      ],
    },
    {
      domainKey: 'settings',
      name: term('navDomainSettings'),
      sections: [
        {
          sectionKey: 'settings-main',
          sectionTitle: null,
          items: [
            { key: 'farm-profile', label: 'Farm Profile', to: '/app/admin/farm', requiredPermission: MODULES },
            { key: 'users', label: 'Users', to: '/app/admin/users', requiredPermission: USERS },
            { key: 'roles', label: 'Roles', to: '/app/admin/roles', requiredPermission: ROLES },
            { key: 'modules', label: 'Modules', to: '/app/admin/modules', requiredPermission: MODULES },
            { key: 'localisation', label: 'Localisation', to: '/app/settings/localisation', requiredPermission: MODULES },
          ],
        },
      ],
    },
  ];
}

/**
 * @deprecated Use getNavDomains for domain/section structure. Flattened for legacy callers/tests.
 */
export function getNavGroups(term: TermFn, showOrchards: boolean, showLivestock: boolean): NavGroup[] {
  const domains = getNavDomains(term, showOrchards, showLivestock);
  return domains.map((d) => ({
    name: d.name,
    items: d.sections.flatMap((s) => s.items),
  }));
}
