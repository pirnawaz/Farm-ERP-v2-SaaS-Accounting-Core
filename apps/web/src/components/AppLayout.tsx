import { useState, useEffect } from 'react';
import { Link, useLocation, useNavigate, Outlet } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useTenant } from '../hooks/useTenant';
import { useAuth, useRole } from '../hooks';
import { useModules } from '../contexts/ModulesContext';
import { BrandLogo } from './BrandLogo';
import { ErrorBoundary } from './ErrorBoundary';
import type { UserRole } from '../types';

type NavigationItem = {
  name: string;
  href: string;
  roles: UserRole[];
  requiredModuleKey?: string;
};

type NavigationGroup = {
  name: string;
  items: NavigationItem[];
};

const navigationGroups: NavigationGroup[] = [
  {
    name: 'Dashboard',
    items: [
      { name: 'Dashboard', href: '/app/dashboard', roles: ['tenant_admin', 'accountant', 'operator'] },
    ],
  },
  {
    name: 'Land Management',
    items: [
      { name: 'Land Parcels', href: '/app/land', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'land' },
    ],
  },
  {
    name: 'Project Management',
    items: [
      { name: 'Crop Cycles', href: '/app/crop-cycles', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
      { name: 'Parties', href: '/app/parties', roles: ['tenant_admin', 'accountant'] },
      { name: 'Allocations', href: '/app/allocations', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
      { name: 'Projects', href: '/app/projects', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
      { name: 'Transactions', href: '/app/transactions', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'projects_crop_cycles' },
    ],
  },
  {
    name: 'Treasury',
    items: [
      { name: 'Settlement', href: '/app/settlement', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'settlements' },
      { name: 'Payments', href: '/app/payments', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'treasury_payments' },
      { name: 'Advances', href: '/app/advances', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'treasury_advances' },
    ],
  },
  {
    name: 'Sales & Receivables',
    items: [
      { name: 'Sales', href: '/app/sales', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'ar_sales' },
    ],
  },
  {
    name: 'Operations',
    items: [
      { name: 'Inventory', href: '/app/inventory', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'inventory' },
      { name: 'Labour', href: '/app/labour', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'labour' },
      { name: 'Crop Ops', href: '/app/crop-ops', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'crop_ops' },
      { name: 'Harvests', href: '/app/harvests', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'crop_ops' },
    ],
  },
  {
    name: 'Machinery',
    items: [
      { name: 'Work Logs', href: '/app/machinery/work-logs', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
      { name: 'Charges', href: '/app/machinery/charges', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
      { name: 'Maintenance Jobs', href: '/app/machinery/maintenance-jobs', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
      { name: 'Profitability', href: '/app/machinery/reports/profitability', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
      { name: 'Machines', href: '/app/machinery/machines', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
      { name: 'Maintenance Types', href: '/app/machinery/maintenance-types', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
      { name: 'Rate Cards', href: '/app/machinery/rate-cards', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'machinery' },
    ],
  },
  {
    name: 'Reports',
    items: [
      { name: 'Trial Balance', href: '/app/reports/trial-balance', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'reports' },
      { name: 'General Ledger', href: '/app/reports/general-ledger', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'reports' },
      { name: 'Project P&L', href: '/app/reports/project-pl', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'reports' },
      { name: 'Crop Cycle P&L', href: '/app/reports/crop-cycle-pl', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'reports' },
      { name: 'Account Balances', href: '/app/reports/account-balances', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'reports' },
      { name: 'Cashbook', href: '/app/reports/cashbook', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'reports' },
      { name: 'AR Ageing', href: '/app/reports/ar-ageing', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'ar_sales' },
      { name: 'Sales Margin', href: '/app/reports/sales-margin', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'reports' },
    ],
  },
  {
    name: 'Settings',
    items: [
      { name: 'Settings', href: '/app/settings/localisation', roles: ['tenant_admin'] },
    ],
  },
  {
    name: 'Administration',
    items: [
      { name: 'Farm Profile', href: '/app/admin/farm', roles: ['tenant_admin'] },
      { name: 'Users', href: '/app/admin/users', roles: ['tenant_admin'] },
      { name: 'Roles', href: '/app/admin/roles', roles: ['tenant_admin'] },
      { name: 'Module Toggles', href: '/app/admin/modules', roles: ['tenant_admin'] },
    ],
  },
];

export function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { tenantId, setTenantId } = useTenant();
  const { userRole, logout } = useAuth();
  const { hasRole } = useRole();
  const { isModuleEnabled } = useModules();

  // Track expanded groups - initialize with groups that contain the active route
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(() => {
    const active = new Set<string>();
    navigationGroups.forEach((group) => {
      const hasActiveItem = group.items.some(
        (item) =>
          location.pathname === item.href &&
          hasRole(item.roles) &&
          (!item.requiredModuleKey || isModuleEnabled(item.requiredModuleKey))
      );
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

  // Filter navigation groups and their items
  const filteredGroups = navigationGroups
    .map((group) => ({
      ...group,
      items: group.items.filter(
        (item) => hasRole(item.roles) && (!item.requiredModuleKey || isModuleEnabled(item.requiredModuleKey))
      ),
    }))
    .filter((group) => group.items.length > 0);

  // Auto-expand groups when navigating to a page within them
  useEffect(() => {
    navigationGroups.forEach((group) => {
      const hasActiveItem = group.items.some(
        (item) =>
          location.pathname === item.href &&
          hasRole(item.roles) &&
          (!item.requiredModuleKey || isModuleEnabled(item.requiredModuleKey))
      );
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

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Sidebar */}
      <div className="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0">
        <div className="flex-1 flex flex-col min-h-0 bg-white border-r border-gray-200">
          <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
            <div className="flex items-center flex-shrink-0 px-4">
              <BrandLogo size="md" />
            </div>
            <nav className="mt-5 flex-1 px-2 space-y-1">
              {filteredGroups.map((group) => {
                const isExpanded = expandedGroups.has(group.name);
                const hasActiveItem = group.items.some((item) => location.pathname === item.href);

                // If group has only one item, render it directly without grouping
                if (group.items.length === 1) {
                  const item = group.items[0];
                  const isActive = location.pathname === item.href;
                  return (
                    <Link
                      key={item.name}
                      to={item.href}
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
                          const isActive = location.pathname === item.href;
                          return (
                            <Link
                              key={item.name}
                              to={item.href}
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
                  const hasActiveItem = group.items.some((item) => location.pathname === item.href);

                  // If group has only one item, render it directly without grouping
                  if (group.items.length === 1) {
                    const item = group.items[0];
                    const isActive = location.pathname === item.href;
                    return (
                      <Link
                        key={item.name}
                        to={item.href}
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
                            const isActive = location.pathname === item.href;
                            return (
                              <Link
                                key={item.name}
                                to={item.href}
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
            <div className="flex-1 flex items-center">
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
              <ErrorBoundary>
                <Outlet />
              </ErrorBoundary>
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
