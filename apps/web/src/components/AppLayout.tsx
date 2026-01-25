import { useState } from 'react';
import { Link, useLocation, useNavigate, Outlet } from 'react-router-dom';
import { useTenant } from '../hooks/useTenant';
import { useAuth, useRole } from '../hooks';
import { useModules } from '../contexts/ModulesContext';
import type { UserRole } from '../types';

const navigation: Array<{ name: string; href: string; roles: UserRole[]; requiredModuleKey?: string }> = [
  { name: 'Dashboard', href: '/app/dashboard', roles: ['tenant_admin', 'accountant', 'operator'] },
  { name: 'Land Parcels', href: '/app/land', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'land' },
  { name: 'Crop Cycles', href: '/app/crop-cycles', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
  { name: 'Parties', href: '/app/parties', roles: ['tenant_admin', 'accountant'] },
  { name: 'Allocations', href: '/app/allocations', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
  { name: 'Projects', href: '/app/projects', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'projects_crop_cycles' },
  { name: 'Transactions', href: '/app/transactions', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'projects_crop_cycles' },
  { name: 'Settlement', href: '/app/settlement', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'settlements' },
  { name: 'Payments', href: '/app/payments', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'treasury_payments' },
  { name: 'Advances', href: '/app/advances', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'treasury_advances' },
  { name: 'Sales', href: '/app/sales', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'ar_sales' },
  { name: 'Inventory', href: '/app/inventory', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'inventory' },
  { name: 'Labour', href: '/app/labour', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'labour' },
  { name: 'Reports', href: '/app/reports', roles: ['tenant_admin', 'accountant', 'operator'], requiredModuleKey: 'reports' },
  { name: 'AR Ageing', href: '/app/reports/ar-ageing', roles: ['tenant_admin', 'accountant'], requiredModuleKey: 'ar_sales' },
  { name: 'Settings', href: '/app/settings/localisation', roles: ['tenant_admin'] },
  { name: 'Farm Profile', href: '/app/admin/farm', roles: ['tenant_admin'] },
  { name: 'Users', href: '/app/admin/users', roles: ['tenant_admin'] },
  { name: 'Module Toggles', href: '/app/admin/modules', roles: ['tenant_admin'] },
];

export function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const { tenantId, setTenantId } = useTenant();
  const { userRole, logout } = useAuth();
  const { hasRole } = useRole();
  const { isModuleEnabled } = useModules();

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  const handleSwitchFarm = () => {
    // Clear tenant, role, and auth token
    setTenantId('');
    logout();
    // Clear tenant ID from localStorage
    localStorage.removeItem('farm_erp_tenant_id');
    navigate('/login');
  };

  const filteredNavigation = navigation.filter(
    (item) => hasRole(item.roles) && (!item.requiredModuleKey || isModuleEnabled(item.requiredModuleKey))
  );

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Sidebar */}
      <div className="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0">
        <div className="flex-1 flex flex-col min-h-0 bg-white border-r border-gray-200">
          <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
            <div className="flex items-center flex-shrink-0 px-4">
              <h1 className="text-xl font-bold text-gray-900">Farm ERP v2</h1>
            </div>
            <nav className="mt-5 flex-1 px-2 space-y-1">
              {filteredNavigation.map((item) => {
                const isActive = location.pathname === item.href;
                return (
                  <Link
                    key={item.name}
                    to={item.href}
                    className={`${
                      isActive
                        ? 'bg-blue-50 text-blue-600'
                        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                    } group flex items-center px-2 py-2 text-sm font-medium rounded-md`}
                  >
                    {item.name}
                  </Link>
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
                <h1 className="text-xl font-bold text-gray-900">Farm ERP v2</h1>
              </div>
              <nav className="mt-5 px-2 space-y-1">
                {filteredNavigation.map((item) => {
                  const isActive = location.pathname === item.href;
                  return (
                    <Link
                      key={item.name}
                      to={item.href}
                      onClick={() => setSidebarOpen(false)}
                      className={`${
                        isActive
                          ? 'bg-blue-50 text-blue-600'
                          : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                      } group flex items-center px-2 py-2 text-sm font-medium rounded-md`}
                    >
                      {item.name}
                    </Link>
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
            className="px-4 border-r border-gray-200 text-gray-500 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 md:hidden"
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
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
              >
                Switch Farm
              </button>
              <button
                onClick={handleLogout}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
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
              <Outlet />
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
