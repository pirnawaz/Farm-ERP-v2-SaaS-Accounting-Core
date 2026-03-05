import { useState } from 'react';
import { useLocation, useNavigate, Outlet } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useTenant } from '../hooks/useTenant';
import { useAuth, useRole } from '../hooks';
import { useModules } from '../contexts/ModulesContext';
import { CropCycleScopeProvider } from '../contexts/CropCycleScopeContext';
import { BrandLogo } from './BrandLogo';
import { ErrorBoundary } from './ErrorBoundary';
import { OnboardingChecklist } from './OnboardingChecklist';
import { CropCycleScopeSelector } from './CropCycleScopeSelector';
import { AppSidebar } from './AppSidebar';

export function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { tenantId, setTenantId } = useTenant();
  const { userRole, logout } = useAuth();
  const { hasRole } = useRole();
  const { loading: modulesLoading, error: modulesError } = useModules();
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

  const handleLogout = () => {
    queryClient.clear();
    logout();
    navigate('/login');
  };

  const handleSwitchFarm = () => {
    // Clear React Query cache so no stale tenant data is shown after switching farms
    queryClient.clear();
    setTenantId('');
    logout();
    localStorage.removeItem('farm_erp_tenant_id');
    navigate('/login');
  };

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
            <AppSidebar />
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
              <AppSidebar onItemClick={() => setSidebarOpen(false)} />
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
