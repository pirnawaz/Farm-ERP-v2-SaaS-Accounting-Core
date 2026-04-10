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
    'loans',
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
  /** Short line under the label in the sidebar (primary-workflow hints). */
  sidebarHint?: string;
  children?: NavItem[];
  submenuKey?: string;
};

export type NavItemGroup = {
  groupTitle: string;
  items: NavItem[];
};

export type NavSection = {
  sectionKey: string;
  /** Optional heading under a domain (e.g. "Land & Crops"). Null = no sub-heading. */
  sectionTitle: string | null;
  items: NavItem[];
  /**
   * Optional labeled subgroups inside a section (sidebar only).
   * When present, use an empty `items` array and put links only in groups.
   */
  itemGroups?: NavItemGroup[];
};

/** Flatten a section's links for pruning, active-route matching, and legacy flatteners. */
export function getSectionNavItems(section: NavSection): NavItem[] {
  if (section.itemGroups?.length) {
    return section.itemGroups.flatMap((g) => g.items);
  }
  return section.items;
}

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
    'project-planning',
    'production-units',
    'land-leases',
    'harvests',
    'crop-ops-agreements',
    'crop-ops-field-jobs',
    'crop-ops-field-work-logs',
    // Machinery (full list for accounting review; field jobs are primary for operators).
    'machinery-overview',
    'machinery-machines',
    'machinery-work-logs',
    'machinery-services',
    'machinery-charges',
    'machinery-maintenance',
    'machinery-maintenance-setup',
    'machinery-rate-cards',
    // Inventory (overview, stock, transactions, setup).
    'inventory-overview',
    'inventory-stock-on-hand',
    'inventory-stock-history',
    'inventory-grns',
    'inventory-issues',
    'inventory-transfers',
    'inventory-adjustments',
    'inventory-items',
    'inventory-categories',
    'inventory-uoms',
    'inventory-stores',
    // Labour and people balances often drive accruals and payments.
    'labour-overview',
    'labour-workers',
    'labour-work-logs',
    'labour-payables',
    'parties',
    // Draft entries are useful context for accounting review.
    'pending-review',

    // Governance (audit/control)
    'farm-integrity',
    'audit-logs',

    // Field planning & forecast (reports + crop)
    'project-forecast',
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
    'project-planning',
    'allocations',
    'land-leases',
    'orchards',
    'livestock',
    'crop-ops-overview',
    'crop-ops-field-jobs',
    'crop-ops-work-types',
    'harvests',
    'crop-ops-agreements',
    'pending-review',
    'machinery-machines',
    'machinery-services',
    'machinery-maintenance',
    'machinery-maintenance-setup',
    'machinery-rate-cards',
    'machinery-overview',
    'inventory-overview',
    'inventory-stock-on-hand',
    'inventory-stock-history',
    'inventory-grns',
    'inventory-transfers',
    'inventory-adjustments',
    'inventory-items',
    'inventory-categories',
    'inventory-uoms',
    'inventory-stores',
    'labour-overview',
    'labour-workers',
    'labour-payables',
    'parties',

    // Limited finance (operationally necessary)
    'payments',
    'advances',
    'loans',
    'fixed-assets',
    'exchange-rates',
    'fx-revaluation-runs',
    'project-forecast',
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

  const pruneSection = (s: NavSection): NavSection => {
    if (s.itemGroups?.length) {
      const itemGroups = s.itemGroups
        .map((g) => ({ ...g, items: pruneItems(g.items) }))
        .filter((g) => g.items.length > 0);
      return { ...s, itemGroups, items: [] };
    }
    return { ...s, items: pruneItems(s.items) };
  };

  return domains
    .filter((d) => allowedDomains.has(d.domainKey))
    .map((d) => ({
      ...d,
      sections: d.sections
        .map((s) => {
          // For accountants we keep all Finance sections as-is (still permission-filtered later).
          if (keepAllFinance && d.domainKey === 'finance') return s;
          return pruneSection(s);
        })
        .filter((s) => getSectionNavItems(s).length > 0),
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

function filterSectionByPermission(s: NavSection, can: CanFn): NavSection {
  if (s.itemGroups?.length) {
    const itemGroups = s.itemGroups
      .map((g) => ({ ...g, items: filterNavItems(g.items, can) }))
      .filter((g) => g.items.length > 0);
    return { ...s, itemGroups, items: [] };
  }
  return { ...s, items: filterNavItems(s.items, can) };
}

/** Drop empty sections/domains after permission filtering (same semantics as pre-refactor sidebar). */
export function filterDomainsByPermission(domains: NavDomain[], can: CanFn): NavDomain[] {
  return domains
    .map((d) => ({
      ...d,
      sections: d.sections
        .map((s) => filterSectionByPermission(s, can))
        .filter((s) => getSectionNavItems(s).length > 0),
    }))
    .filter((d) => d.sections.length > 0);
}

function financeSectionItems(term: TermFn, VIEW: PermissionKey): {
  moneyTreasury: NavItem[];
  accounting: NavItem[];
  reports: NavItem[];
  analysis: NavItem[];
  receivables: NavItem[];
  payables: NavItem[];
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
      {
        key: 'fixed-assets',
        label: 'Fixed assets',
        to: '/app/accounting/fixed-assets',
        requiredPermission: VIEW,
        requiredModules: ['reports'],
      },
      {
        key: 'exchange-rates',
        label: 'Exchange rates',
        to: '/app/accounting/exchange-rates',
        requiredPermission: VIEW,
        requiredModules: ['reports'],
      },
      {
        key: 'fx-revaluation-runs',
        label: 'FX revaluation',
        to: '/app/accounting/fx-revaluation-runs',
        requiredPermission: VIEW,
        requiredModules: ['reports'],
      },
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
      { key: 'settlement-packs', label: 'Settlement Packs', to: '/app/settlement-packs', requiredPermission: VIEW, requiredModules: ['settlements'] },
      { key: 'loans', label: 'Loans', to: '/app/loans', requiredPermission: VIEW, requiredModules: ['loans'] },
    ],
    analysis: [
      { key: 'crop-profitability', label: 'Crop Profitability', to: '/app/reports/crop-profitability', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
      { key: 'project-profitability', label: 'Field cycle profit', to: '/app/reports/project-profitability', requiredPermission: VIEW, requiredModules: ['reports', 'projects_crop_cycles'] },
      {
        key: 'project-forecast',
        label: 'Field forecast',
        to: '/app/reports/project-forecast',
        requiredPermission: VIEW,
        requiredModules: ['reports', 'projects_crop_cycles'],
      },
      { key: 'project-pl', label: 'Field Cycle P&L', to: '/app/reports/project-pl', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'crop-cycle-pl', label: 'Crop Cycle P&L', to: '/app/reports/crop-cycle-pl', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'profitability-trend', label: 'Profitability Trend', to: '/app/reports/crop-profitability-trend', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
      { key: 'sales-margin', label: 'Sales Margin', to: '/app/reports/sales-margin', requiredPermission: VIEW, requiredModules: ['reports'] },
      { key: 'machinery-profitability', label: 'Machine profit', to: '/app/reports/machine-profitability', requiredPermission: VIEW, requiredModules: ['reports'] },
    ],
    receivables: [{ key: 'ar-ageing', label: term('arAgeing'), to: '/app/reports/ar-ageing', requiredPermission: VIEW, requiredModules: ['ar_sales'] }],
    payables: [
      { key: 'ap-ageing', label: 'AP ageing', to: '/app/reports/ap-ageing', requiredPermission: VIEW, requiredModules: ['reports'] },
      {
        key: 'supplier-invoices',
        label: 'Supplier invoices',
        to: '/app/accounting/supplier-invoices',
        requiredPermission: VIEW,
        requiredModules: ['reports'],
      },
    ],
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
  const hasOrchardLivestockAddons = showOrchards || showLivestock;

  const inventoryItems: NavItem[] = [
    { key: 'inventory-overview', label: 'Inventory Overview', to: '/app/inventory', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-stock-on-hand', label: 'Current Stock', to: '/app/inventory/stock-on-hand', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-stock-history', label: 'Stock History', to: '/app/inventory/stock-movements', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-grns', label: 'Goods Received', to: '/app/inventory/grns', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-issues', label: 'Stock Used', to: '/app/inventory/issues', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-transfers', label: 'Transfer Stock', to: '/app/inventory/transfers', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-adjustments', label: 'Adjust Stock', to: '/app/inventory/adjustments', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-items', label: 'Items', to: '/app/inventory/items', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-categories', label: 'Categories', to: '/app/inventory/categories', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-uoms', label: 'Units', to: '/app/inventory/uoms', requiredPermission: VIEW, requiredModules: ['inventory'] },
    { key: 'inventory-stores', label: 'Stores', to: '/app/inventory/stores', requiredPermission: VIEW, requiredModules: ['inventory'] },
  ];

  const machineryItems: NavItem[] = [
    { key: 'machinery-overview', label: 'Machinery Overview', to: '/app/machinery', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-machines', label: 'Machines', to: '/app/machinery/machines', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-work-logs', label: 'Machine Usage', to: '/app/machinery/work-logs', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-services', label: 'Service History', to: '/app/machinery/services', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-charges', label: 'Machinery Charges', to: '/app/machinery/charges', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-maintenance', label: 'Maintenance Jobs', to: '/app/machinery/maintenance-jobs', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-maintenance-setup', label: 'Maintenance Setup', to: '/app/machinery/maintenance-types', requiredPermission: VIEW, requiredModules: ['machinery'] },
    { key: 'machinery-rate-cards', label: 'Rate Cards', to: '/app/machinery/rate-cards', requiredPermission: VIEW, requiredModules: ['machinery'] },
  ];

  /** Land & Crops: grouped in sidebar only (keys, routes, modules unchanged). */
  const landCropItemGroups: NavItemGroup[] = [
    {
      groupTitle: 'Land Setup',
      items: [
        { key: 'land', label: 'Land Parcels', to: '/app/land', requiredPermission: VIEW, requiredModules: ['land'] },
        { key: 'allocations', label: 'Land Allocation', to: '/app/allocations', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        { key: 'land-leases', label: 'Land Leases (Maqada)', to: '/app/land-leases', requiredPermission: MODULES, requiredModules: ['land_leases'] },
      ],
    },
    {
      groupTitle: 'Crop Planning',
      items: [
        { key: 'crop-cycles', label: 'Crop Cycles', to: '/app/crop-cycles', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        { key: 'fields', label: term('navFields'), to: '/app/projects', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
        {
          key: 'project-planning',
          label: 'Field plan & forecast',
          to: '/app/planning',
          requiredPermission: VIEW,
          requiredModules: ['projects_crop_cycles', 'reports'],
          sidebarHint: 'Expected costs, yield, and gap vs actuals',
        },
        ...(showOrchards
          ? [{ key: 'orchards', label: 'Orchards', to: '/app/orchards', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] } as NavItem]
          : []),
        ...(showLivestock
          ? [{ key: 'livestock', label: 'Livestock', to: '/app/livestock', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] } as NavItem]
          : []),
      ],
    },
    ...(hasOrchardLivestockAddons
      ? [
          {
            groupTitle: 'Advanced',
            items: [
              {
                key: 'production-units',
                label: 'Production Units (Advanced)',
                to: '/app/production-units',
                requiredPermission: VIEW,
                requiredModules: ['projects_crop_cycles'],
              },
            ],
          },
        ]
      : []),
  ];

  /** Work & Harvest: Crop Ops lifecycle + drafts (grouped in sidebar only). */
  const workHarvestItemGroups: NavItemGroup[] = [
    {
      groupTitle: 'Crop Ops',
      items: [
        { key: 'crop-ops-overview', label: 'Crop Ops Overview', to: '/app/crop-ops', requiredPermission: VIEW, requiredModules: ['crop_ops'] },
        { key: 'crop-ops-field-work-logs', label: 'Field Work Logs', to: '/app/crop-ops/activities', requiredPermission: VIEW, requiredModules: ['crop_ops'] },
        {
          key: 'crop-ops-field-jobs',
          label: 'Field Jobs',
          to: '/app/crop-ops/field-jobs',
          requiredPermission: VIEW,
          requiredModules: ['crop_ops'],
          sidebarHint: 'Use Field Jobs to record work',
        },
        {
          key: 'harvests',
          label: 'Harvests',
          to: '/app/harvests',
          requiredPermission: VIEW,
          requiredModules: ['crop_ops'],
          sidebarHint: 'Use Harvest to record output and sharing',
        },
        {
          key: 'crop-ops-agreements',
          label: 'Agreements',
          to: '/app/crop-ops/agreements',
          requiredPermission: VIEW,
          requiredModules: ['crop_ops'],
          sidebarHint: 'Output share rules for suggestions',
        },
        { key: 'crop-ops-work-types', label: 'Work Types', to: '/app/crop-ops/activity-types', requiredPermission: VIEW, requiredModules: ['crop_ops'] },
      ],
    },
    {
      groupTitle: 'Other',
      items: [
        { key: 'pending-review', label: 'Draft Entries', to: '/app/transactions', requiredPermission: VIEW, requiredModules: ['projects_crop_cycles'] },
      ],
    },
  ];

  const labourNavItems: NavItem[] = [
    { key: 'labour-overview', label: 'Labour Overview', to: '/app/labour', requiredPermission: VIEW, requiredModules: ['labour'] },
    { key: 'labour-workers', label: 'Workers', to: '/app/labour/workers', requiredPermission: VIEW, requiredModules: ['labour'] },
    { key: 'labour-work-logs', label: 'Labour Work Logs', to: '/app/labour/work-logs', requiredPermission: VIEW, requiredModules: ['labour'] },
    { key: 'labour-payables', label: 'Payables', to: '/app/labour/payables', requiredPermission: VIEW, requiredModules: ['labour'] },
  ];
  const partiesNavItem: NavItem = {
    key: 'parties',
    label: 'People & Partners',
    to: '/app/parties',
    requiredPermission: VIEW,
  };

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
        {
          sectionKey: 'ops-land-crops',
          sectionTitle: 'Land & Crops',
          items: [],
          itemGroups: landCropItemGroups,
        },
        {
          sectionKey: 'ops-work-harvest',
          sectionTitle: 'Work & Harvest',
          items: [],
          itemGroups: workHarvestItemGroups,
        },
        { sectionKey: 'ops-machinery', sectionTitle: 'Machinery', items: machineryItems },
        {
          sectionKey: 'ops-inventory',
          sectionTitle: 'Inventory',
          items: inventoryItems,
        },
        {
          sectionKey: 'ops-people',
          sectionTitle: 'People & Workforce',
          items: [],
          itemGroups: [
            { groupTitle: 'Workforce', items: labourNavItems },
            { groupTitle: 'Directory', items: [partiesNavItem] },
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
          items: [
            ...financeSectionItems(term, VIEW).analysis,
            ...(hasOrchardLivestockAddons
              ? [
                  {
                    key: 'production-units-profitability',
                    label: 'Orchard & Livestock performance',
                    to: '/app/reports/production-units-profitability',
                    requiredPermission: VIEW,
                    requiredModules: ['projects_crop_cycles'],
                  } as NavItem,
                ]
              : []),
          ],
        },
        {
          sectionKey: 'fin-receivables',
          sectionTitle: 'Receivables',
          items: financeSectionItems(term, VIEW).receivables,
        },
        {
          sectionKey: 'fin-payables',
          sectionTitle: 'Payables',
          items: financeSectionItems(term, VIEW).payables,
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
    items: d.sections.flatMap((s) => getSectionNavItems(s)),
  }));
}
