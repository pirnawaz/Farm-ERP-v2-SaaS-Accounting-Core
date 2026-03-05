import { useState, useEffect } from 'react';
import { Link, useLocation, useNavigate, Outlet } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useTenant } from '../hooks/useTenant';
import { useAuth, useRole } from '../hooks';
import { useTenantAddonModulesQuery } from '../hooks/useModules';
import { useModules } from '../contexts/ModulesContext';
import { CropCycleScopeProvider } from '../contexts/CropCycleScopeContext';
import { BrandLogo } from './BrandLogo';
import { ErrorBoundary } from './ErrorBoundary';
import { OnboardingChecklist } from './OnboardingChecklist';
import { CropCycleScopeSelector } from './CropCycleScopeSelector';
import type { UserRole } from '../types';
import { term } from '../config/terminology';

type NavigationItem = {
  name: string;
  href: string;
  roles: UserRole[];
  requiredModuleKey?: string;
};

type NavigationParent = {
  name: string;
  submenuKey: string;
  children: NavigationItem[];
};

function isSubmenuParent(item: NavigationItem | NavigationParent): item is NavigationParent {
  return 'children' in item && Array.isArray((item as NavigationParent).children);
}

type NavigationGroup = {
  name: string;
  items: (NavigationItem | NavigationParent)[];
};

// Farm-first navigation: FARM / PEOPLE / MONEY / ACCOUNTING / SETTINGS.
// Orchards and Livestock are add-on expansions (GET /api/tenant/addon-modules or env override).
// Operator: FARM + PEOPLE. Accountant: FARM + PEOPLE + MONEY + ACCOUNTING. tenant_admin: all. SETTINGS: tenant_admin only.
function getNavigationGroups(showOrchards: boolean, showLivestock: boolean): NavigationGroup[] {
  return [
    {
      name: term('navFarm'),
      items: [
        { name: 'Farm Pulse', href: '/app/farm-pulse', roles: ['tenant_admin', 'accountant', 'operator'] },
        { name: 'Today', href: '/app/today', roles: ['tenant_admin', 'accountant', 'operator'] },
        { name: 'Alerts', href: '/app/alerts', roles: ['tenant_admin', 'accountant', 'operator'] },
        { name: term('navFields'), href: '/app/projects', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'projects_crop_cycles' },
        { name: term('navWork'), href: '/app/crop-ops', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'crop_ops' },
        { name: 'Harvests', href: '/app/harvests', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'crop_ops' },
        { name: term('pendingReview'), href: '/app/transactions', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'projects_crop_cycles' },
        {
          name: 'Machinery',
          submenuKey: 'machinery',
          children: [
            { name: 'Machines', href: '/app/machinery/machines', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
            { name: 'Work Logs', href: '/app/machinery/work-logs', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
            { name: 'Services', href: '/app/machinery/services', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
            { name: 'Charges', href: '/app/machinery/charges', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
            { name: 'Maintenance', href: '/app/machinery/maintenance-jobs', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
            { name: 'Maintenance Setup', href: '/app/machinery/maintenance-types', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
            { name: 'Rate Cards', href: '/app/machinery/rate-cards', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
          ],
        },
        { name: 'Inventory', href: '/app/inventory', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'inventory' },
        { name: 'Land Parcels', href: '/app/land', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'land' },
        { name: 'Land Leases (Maqada)', href: '/app/land-leases', roles: ['tenant_admin'], requiredModuleKey: 'land_leases' },
        { name: 'Crop Cycles', href: '/app/crop-cycles', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
        { name: 'Production Units', href: '/app/production-units', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
        ...(showOrchards ? [{ name: 'Orchards', href: '/app/orchards', roles: ['tenant_admin', 'accountant', 'operator'] as UserRole[], requiredModuleKey: 'projects_crop_cycles' }] : []),
        ...(showLivestock ? [{ name: 'Livestock', href: '/app/livestock', roles: ['tenant_admin', 'accountant', 'operator'] as UserRole[], requiredModuleKey: 'projects_crop_cycles' }] : []),
        { name: 'Land Allocation', href: '/app/allocations', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
      ],
    },
    {
      name: term('navPeople'),
      items: [
        { name: 'Labour', href: '/app/labour', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'labour' },
        { name: 'People & Partners', href: '/app/parties', roles: ['tenant_admin', 'accountant', 'operator'] },
      ],
    },
    {
      name: term('navMoney'),
      items: [
        { name: 'Sales & Money', href: '/app/sales', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'ar_sales' },
        { name: term('navPayReceive'), href: '/app/payments', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'treasury_payments' },
        { name: 'Advances', href: '/app/advances', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'treasury_advances' },
      ],
    },
    {
      name: term('navAccounting'),
    items: [
      { name: 'Accounting Overview', href: '/app/dashboard', roles: ['tenant_admin', 'accountant'] },
      { name: term('reviewQueue'), href: '/app/review-queue', roles: ['tenant_admin', 'accountant'] },
      { name: 'Account Balances', href: '/app/reports/account-balances', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Crop Profitability', href: '/app/reports/crop-profitability', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
      { name: term('arAgeing'), href: '/app/reports/ar-ageing', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'ar_sales' },
      { name: 'Bank Reconciliation', href: '/app/reports/bank-reconciliation', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: term('trialBalance'), href: '/app/reports/trial-balance', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Governance', href: '/app/governance', roles: ['tenant_admin', 'accountant'] },
      { name: term('profitAndLoss'), href: '/app/reports/profit-loss', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: term('balanceSheet'), href: '/app/reports/balance-sheet', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Cashbook', href: '/app/reports/cashbook', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Project P&L', href: '/app/reports/project-pl', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Crop Cycle P&L', href: '/app/reports/crop-cycle-pl', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Profitability Trend', href: '/app/reports/crop-profitability-trend', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
      { name: 'Machinery Profitability', href: '/app/machinery/reports/profitability', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'machinery' },
      { name: 'Sales Margin', href: '/app/reports/sales-margin', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Party Ledger', href: '/app/reports/party-ledger', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Landlord Statement', href: '/app/reports/landlord-statement', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'land_leases' },
      { name: 'Party Summary', href: '/app/reports/party-summary', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Party Ageing', href: '/app/reports/role-ageing', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Reconcile Accounts', href: '/app/reports/reconciliation-dashboard', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: term('generalLedger'), href: '/app/reports/general-ledger', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'General Journal', href: '/app/accounting/journals', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
      { name: 'Settlement Packs', href: '/app/settlement', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'settlements' },
      { name: 'Accounting Periods', href: '/app/accounting/periods', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'reports' },
    ],
  },
  {
    name: term('navSettings'),
    items: [
      { name: 'Farm Profile', href: '/app/admin/farm', roles: ['tenant_admin'] },
      { name: 'Users', href: '/app/admin/users', roles: ['tenant_admin'] },
      { name: 'Roles', href: '/app/admin/roles', roles: ['tenant_admin'] },
      { name: 'Audit Logs', href: '/app/admin/audit-logs', roles: ['tenant_admin'] },
      { name: 'Modules', href: '/app/admin/modules', roles: ['tenant_admin'] },
      { name: 'Farm Integrity', href: '/app/internal/farm-integrity', roles: ['tenant_admin'] },
      { name: 'Localisation', href: '/app/settings/localisation', roles: ['tenant_admin'] },
    ],
  },
];
}

export function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { tenantId, setTenantId } = useTenant();
  const { userRole, logout } = useAuth();
  const { hasRole } = useRole();
  const { isModuleEnabled, loading: modulesLoading, error: modulesError } = useModules();
  const { data: addonModulesData, status: addonModulesStatus } = useTenantAddonModulesQuery();
  const envOrchards = import.meta.env.VITE_ENABLE_ORCHARDS === 'true';
  const envLivestock = import.meta.env.VITE_ENABLE_LIVESTOCK === 'true';
  const showOrchards = envOrchards || (addonModulesStatus === 'success' && addonModulesData?.modules?.orchards === true);
  const showLivestock = envLivestock || (addonModulesStatus === 'success' && addonModulesData?.modules?.livestock === true);
  const navigationGroups = getNavigationGroups(showOrchards, showLivestock);
  // TEMP: force-all => ready immediately so E2E waitForModulesReady is stable.
  const forceAllModules =
    import.meta.env.VITE_FORCE_ALL_MODULES_ENABLED === 'true' ||
    (typeof import.meta.env.VITE_FORCE_ALL_MODULES_ENABLED === 'string' &&
      import.meta.env.VITE_FORCE_ALL_MODULES_ENABLED.length > 0);
  const modulesState: 'loading' | 'error' | 'ready' = forceAllModules
    ? 'ready'
    : modulesLoading
      ? 'loading'
      : modulesError
        ? 'error'
        : 'ready';

  const SIDEBAR_EXPANDED_KEY = 'terrava.sidebar.expanded';

  const getSubmenuStorageKey = () => `${SIDEBAR_EXPANDED_KEY}.${tenantId ?? '_default'}`;

  const loadSubmenuExpanded = (): Record<string, boolean> => {
    try {
      const raw = localStorage.getItem(getSubmenuStorageKey());
      if (raw) {
        const parsed = JSON.parse(raw) as Record<string, boolean>;
        if (parsed && typeof parsed === 'object') return parsed;
      }
    } catch {
      // ignore
    }
    return {};
  };

  const saveSubmenuExpanded = (map: Record<string, boolean>) => {
    try {
      localStorage.setItem(getSubmenuStorageKey(), JSON.stringify(map));
    } catch {
      // ignore
    }
  };

  const isOnMachineryRoute = location.pathname.startsWith('/app/machinery');
  const [expandedSubmenus, setExpandedSubmenus] = useState<Record<string, boolean>>(() => {
    const saved = loadSubmenuExpanded();
    return { machinery: saved.machinery ?? isOnMachineryRoute, ...saved };
  });

  useEffect(() => {
    const key = getSubmenuStorageKey();
    const saved = (() => {
      try {
        const raw = localStorage.getItem(key);
        return raw ? (JSON.parse(raw) as Record<string, boolean>) : {};
      } catch {
        return {};
      }
    })();
    setExpandedSubmenus((_prev) => ({ ...saved, machinery: saved.machinery ?? isOnMachineryRoute }));
  }, [tenantId]);

  const toggleSubmenu = (submenuKey: string) => {
    setExpandedSubmenus((prev) => {
      const next = { ...prev, [submenuKey]: !prev[submenuKey] };
      saveSubmenuExpanded(next);
      return next;
    });
  };

  // Track expanded groups - FARM default expanded; also expand group containing active route
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(() => {
    const active = new Set<string>();
    active.add(term('navFarm'));
    navigationGroups.forEach((group) => {
      const hasActiveItem = group.items.some((item) => {
        if (isSubmenuParent(item)) {
          const visible = item.children.filter(
            (c) => hasRole(c.roles) && (!c.requiredModuleKey || isModuleEnabled(c.requiredModuleKey))
          );
          return visible.some(
            (c) => location.pathname === c.href || location.pathname.startsWith(c.href + '/')
          );
        }
        return (
          location.pathname === item.href &&
          hasRole(item.roles) &&
          (!item.requiredModuleKey || isModuleEnabled(item.requiredModuleKey))
        );
      });
      if (hasActiveItem) {
        active.add(group.name);
      }
    });
    return active;
  });

  const handleLogout = () => {
    queryClient.clear();
    logout();
    navigate('/login');
  };

  const handleSwitchFarm = () => {
    // Clear React Query cache so no stale tenant data is shown after switching farms
    queryClient.clear();
    // Clear tenant, role, and auth token
    setTenantId('');
    logout();
    // Clear tenant ID from localStorage
    localStorage.removeItem('farm_erp_tenant_id');
    navigate('/login');
  };

  const toggleGroup = (groupName: string) => {
    setExpandedGroups((prev) => {
      const next = new Set(prev);
      if (next.has(groupName)) {
        next.delete(groupName);
      } else {
        next.add(groupName);
      }
      return next;
    });
  };

  // Filter navigation groups and their items; for submenu parents, filter children and keep parent only if at least one child is visible
  const filteredGroups: NavigationGroup[] = navigationGroups
    .map((group) => ({
      ...group,
      items: group.items.flatMap((item): (NavigationItem | NavigationParent)[] => {
        if (isSubmenuParent(item)) {
          const visibleChildren = item.children.filter(
            (c) => hasRole(c.roles) && (!c.requiredModuleKey || isModuleEnabled(c.requiredModuleKey))
          );
          if (visibleChildren.length === 0) return [];
          return [{ ...item, children: visibleChildren }];
        }
        if (hasRole(item.roles) && (!item.requiredModuleKey || isModuleEnabled(item.requiredModuleKey))) {
          return [item];
        }
        return [];
      }),
    }))
    .filter((group) => group.items.length > 0);

  // Auto-expand groups when navigating to a page within them
  useEffect(() => {
    navigationGroups.forEach((group) => {
      const hasActiveItem = group.items.some((item) => {
        if (isSubmenuParent(item)) {
          const visible = item.children.filter(
            (c) => hasRole(c.roles) && (!c.requiredModuleKey || isModuleEnabled(c.requiredModuleKey))
          );
          return visible.some(
            (c) => location.pathname === c.href || location.pathname.startsWith(c.href + '/')
          );
        }
        return (
          location.pathname === item.href &&
          hasRole(item.roles) &&
          (!item.requiredModuleKey || isModuleEnabled(item.requiredModuleKey))
        );
      });
      if (hasActiveItem) {
        setExpandedGroups((prev) => {
          if (!prev.has(group.name)) {
            return new Set(prev).add(group.name);
          }
          return prev;
        });
      }
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname]);

  // Auto-expand Machinery submenu when navigating to a machinery route (do not persist; only user toggle persists)
  useEffect(() => {
    if (location.pathname.startsWith('/app/machinery') && !expandedSubmenus.machinery) {
      setExpandedSubmenus((prev) => ({ ...prev, machinery: true }));
    }
  }, [location.pathname]);

  const isTenantApp = location.pathname.startsWith('/app') && !location.pathname.startsWith('/app/platform');

  return (
    <CropCycleScopeProvider tenantId={tenantId}>
    <div className="min-h-screen bg-gray-50" data-testid="app-shell">
      {/* Readiness marker for E2E: state encoded in data-state (loading | error | ready) */}
      <div
        data-testid="modules-ready"
        data-state={modulesState}
        {...(modulesError && { 'data-modules-error': modulesError.message })}
        className="sr-only"
        aria-hidden="true"
      />
      {/* Sidebar */}
      <div className="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0" data-testid="app-sidebar">
        <div className="flex-1 flex flex-col min-h-0 bg-white border-r border-gray-200">
          <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
            <div className="flex items-center flex-shrink-0 px-4">
              <BrandLogo size="md" />
            </div>
            <nav className="mt-5 flex-1 px-2 space-y-1">
              {filteredGroups.map((group) => {
                const isExpanded = expandedGroups.has(group.name);
                const hasActiveItem = group.items.some((item) => {
                  if (isSubmenuParent(item)) {
                    return item.children.some(
                      (c) => location.pathname === c.href || location.pathname.startsWith(c.href + '/')
                    );
                  }
                  return location.pathname === item.href;
                });

                // If group has only one item, render it directly without grouping (and no submenu)
                if (group.items.length === 1 && !isSubmenuParent(group.items[0])) {
                  const item = group.items[0];
                  const isActive = location.pathname === item.href;
                  const navTestId = `nav-${item.href.replace('/app/', '').replace(/\//g, '-')}`;
                  return (
                    <Link
                      key={item.name}
                      to={item.href}
                      data-testid={navTestId}
                      className={`${
                        isActive
                          ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                          : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                      } group flex items-center px-2 py-2 text-sm font-medium rounded-md`}
                    >
                      {item.name}
                    </Link>
                  );
                }

                // Render group with collapsible items
                return (
                  <div key={group.name}>
                    <button
                      onClick={() => toggleGroup(group.name)}
                      className={`${
                        hasActiveItem
                          ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                          : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                      } w-full group flex items-center justify-between px-2 py-2 text-sm font-medium rounded-md`}
                    >
                      <span>{group.name}</span>
                      <svg
                        className={`h-4 w-4 transition-transform ${isExpanded ? 'transform rotate-90' : ''}`}
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                      >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                      </svg>
                    </button>
                    {isExpanded && (
                      <div className="ml-4 mt-1 space-y-1">
                        {group.items.map((item) => {
                          if (isSubmenuParent(item)) {
                            const subExpanded = expandedSubmenus[item.submenuKey] ?? false;
                            const hasChildActive = item.children.some(
                              (c) => location.pathname === c.href || location.pathname.startsWith(c.href + '/')
                            );
                            return (
                              <div key={item.submenuKey}>
                                <button
                                  type="button"
                                  onClick={() => toggleSubmenu(item.submenuKey)}
                                  aria-expanded={subExpanded}
                                  onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                      e.preventDefault();
                                      toggleSubmenu(item.submenuKey);
                                    }
                                  }}
                                  className={`${
                                    hasChildActive
                                      ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                                      : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                  } w-full group flex items-center justify-between px-2 py-2 text-sm font-medium rounded-md`}
                                >
                                  <span>{item.name}</span>
                                  <svg
                                    className={`h-4 w-4 transition-transform ${subExpanded ? 'transform rotate-90' : ''}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                  >
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                  </svg>
                                </button>
                                {subExpanded && (
                                  <div className="ml-4 mt-1 space-y-1 pl-2 border-l border-gray-200">
                                    {item.children.map((child) => {
                                      const isActive = location.pathname === child.href || location.pathname.startsWith(child.href + '/');
                                      const navTestId = `nav-${child.href.replace('/app/', '').replace(/\//g, '-')}`;
                                      return (
                                        <Link
                                          key={child.href}
                                          to={child.href}
                                          data-testid={navTestId}
                                          className={`${
                                            isActive
                                              ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                                              : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                          } group flex items-center px-2 py-2 text-sm font-medium rounded-md`}
                                        >
                                          {child.name}
                                        </Link>
                                      );
                                    })}
                                  </div>
                                )}
                              </div>
                            );
                          }
                          const isActive = location.pathname === item.href;
                          const navTestId = `nav-${item.href.replace('/app/', '').replace(/\//g, '-')}`;
                          return (
                            <Link
                              key={item.name}
                              to={item.href}
                              data-testid={navTestId}
                              className={`${
                                isActive
                                  ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                                  : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                              } group flex items-center px-2 py-2 text-sm font-medium rounded-md`}
                            >
                              {item.name}
                            </Link>
                          );
                        })}
                      </div>
                    )}
                  </div>
                );
              })}
            </nav>
          </div>
        </div>
      </div>

      {/* Mobile sidebar */}
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="fixed inset-0 bg-gray-600 bg-opacity-75" onClick={() => setSidebarOpen(false)} />
          <div className="relative flex-1 flex flex-col max-w-xs w-full bg-white">
            <div className="absolute top-0 right-0 -mr-12 pt-2">
              <button
                onClick={() => setSidebarOpen(false)}
                className="ml-1 flex items-center justify-center h-10 w-10 rounded-full focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white"
              >
                <span className="sr-only">Close sidebar</span>
                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div className="flex-1 h-0 pt-5 pb-4 overflow-y-auto">
              <div className="flex-shrink-0 flex items-center px-4">
                <BrandLogo size="md" />
              </div>
              <nav className="mt-5 px-2 space-y-1">
                {filteredGroups.map((group) => {
                  const isExpanded = expandedGroups.has(group.name);
                  const hasActiveItem = group.items.some((item) => {
                    if (isSubmenuParent(item)) {
                      return item.children.some(
                        (c) => location.pathname === c.href || location.pathname.startsWith(c.href + '/')
                      );
                    }
                    return location.pathname === item.href;
                  });

                  // If group has only one item, render it directly without grouping (and no submenu)
                  if (group.items.length === 1 && !isSubmenuParent(group.items[0])) {
                    const item = group.items[0];
                    const isActive = location.pathname === item.href;
                    const navTestId = `nav-${item.href.replace('/app/', '').replace(/\//g, '-')}`;
                    return (
                      <Link
                        key={item.name}
                        to={item.href}
                        data-testid={navTestId}
                        onClick={() => setSidebarOpen(false)}
                        className={`${
                          isActive
                            ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                        } group flex items-center px-2 py-2 text-sm font-medium rounded-md`}
                      >
                        {item.name}
                      </Link>
                    );
                  }

                  // Render group with collapsible items
                  return (
                    <div key={group.name}>
                      <button
                        onClick={() => toggleGroup(group.name)}
                        className={`${
                          hasActiveItem
                            ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                        } w-full group flex items-center justify-between px-2 py-2 text-sm font-medium rounded-md`}
                      >
                        <span>{group.name}</span>
                        <svg
                          className={`h-4 w-4 transition-transform ${isExpanded ? 'transform rotate-90' : ''}`}
                          fill="none"
                          viewBox="0 0 24 24"
                          stroke="currentColor"
                        >
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                        </svg>
                      </button>
                      {isExpanded && (
                        <div className="ml-4 mt-1 space-y-1">
                          {group.items.map((item) => {
                            if (isSubmenuParent(item)) {
                              const subExpanded = expandedSubmenus[item.submenuKey] ?? false;
                              const hasChildActive = item.children.some(
                                (c) => location.pathname === c.href || location.pathname.startsWith(c.href + '/')
                              );
                              return (
                                <div key={item.submenuKey}>
                                  <button
                                    type="button"
                                    onClick={() => toggleSubmenu(item.submenuKey)}
                                    aria-expanded={subExpanded}
                                    onKeyDown={(e) => {
                                      if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        toggleSubmenu(item.submenuKey);
                                      }
                                    }}
                                    className={`${
                                      hasChildActive
                                        ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                                        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                    } w-full group flex items-center justify-between px-2 py-2 text-sm font-medium rounded-md`}
                                  >
                                    <span>{item.name}</span>
                                    <svg
                                      className={`h-4 w-4 transition-transform ${subExpanded ? 'transform rotate-90' : ''}`}
                                      fill="none"
                                      viewBox="0 0 24 24"
                                      stroke="currentColor"
                                    >
                                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                    </svg>
                                  </button>
                                  {subExpanded && (
                                    <div className="ml-4 mt-1 space-y-1 pl-2 border-l border-gray-200">
                                      {item.children.map((child) => {
                                        const isActive = location.pathname === child.href || location.pathname.startsWith(child.href + '/');
                                        const navTestId = `nav-${child.href.replace('/app/', '').replace(/\//g, '-')}`;
                                        return (
                                          <Link
                                            key={child.href}
                                            to={child.href}
                                            data-testid={navTestId}
                                            onClick={() => setSidebarOpen(false)}
                                            className={`${
                                              isActive
                                                ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                                                : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                            } group flex items-center px-2 py-2 text-sm font-medium rounded-md`}
                                          >
                                            {child.name}
                                          </Link>
                                        );
                                      })}
                                    </div>
                                  )}
                                </div>
                              );
                            }
                            const isActive = location.pathname === item.href;
                            const navTestId = `nav-${item.href.replace('/app/', '').replace(/\//g, '-')}`;
                            return (
                              <Link
                                key={item.name}
                                to={item.href}
                                data-testid={navTestId}
                                onClick={() => setSidebarOpen(false)}
                                className={`${
                                  isActive
                                    ? 'bg-[#E6ECEA] text-[#1F6F5C]'
                                    : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                } group flex items-center px-2 py-2 text-sm font-medium rounded-md`}
                              >
                                {item.name}
                              </Link>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  );
                })}
              </nav>
            </div>
          </div>
        </div>
      )}

      {/* Main content */}
      <div className="md:pl-64 flex flex-col flex-1">
        {/* Header */}
        <div className="sticky top-0 z-10 flex-shrink-0 flex h-16 bg-white shadow">
          <button
            type="button"
            className="px-4 border-r border-gray-200 text-gray-500 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-[#1F6F5C] md:hidden"
            onClick={() => setSidebarOpen(true)}
          >
            <span className="sr-only">Open sidebar</span>
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h7" />
            </svg>
          </button>
            <div className="flex-1 px-4 flex justify-between items-center">
            <div className="flex-1 flex items-center gap-4">
              {isTenantApp && <CropCycleScopeSelector />}
              <div className="flex items-center space-x-4">
                <div className="text-sm text-gray-600">
                  Tenant ID: <span className="font-mono text-xs text-gray-500">{tenantId ? `${tenantId.substring(0, 8)}...` : 'None'}</span>
                </div>
                <div className="text-sm text-gray-600">
                  Role: <span className="font-medium text-gray-900">{userRole || 'None'}</span>
                </div>
              </div>
            </div>
            <div className="ml-4 flex items-center space-x-2 md:ml-6">
              <button
                onClick={handleSwitchFarm}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
              >
                Switch Farm
              </button>
              <button
                onClick={handleLogout}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
              >
                Logout
              </button>
            </div>
          </div>
        </div>

        {/* Page content */}
        <main className="flex-1">
          <div className="py-6">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 md:px-8">
              {hasRole(['tenant_admin']) && <OnboardingChecklist />}
              <ErrorBoundary>
                <Outlet />
              </ErrorBoundary>
            </div>
          </div>
        </main>
      </div>
    </div>
    </CropCycleScopeProvider>
  );
}
