import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { useAuth } from '../hooks/useAuth';
import { getApiErrorMessage } from '../utils/api';
import toast from 'react-hot-toast';
import { useCropCycleScope } from '../contexts/CropCycleScopeContext';
import { useOnboardingState } from '../hooks/useOnboardingState';
import { OnboardingPanel } from '../components/OnboardingPanel';
import { LoadingSpinner } from '../components/LoadingSpinner';
import {
  DashboardViewSelector,
  getStoredView,
  setStoredView,
  getDefaultViewForRole,
} from '../components/dashboard/DashboardViewSelector';
import { OwnerLayout } from '../components/dashboard/OwnerLayout';
import { ManagerLayout } from '../components/dashboard/ManagerLayout';
import { AccountantLayout } from '../components/dashboard/AccountantLayout';
import type { DashboardViewType } from '../components/dashboard/dashboardTypes';

export default function DashboardPage() {
  const { tenantId, userRole } = useAuth();
  const { scopeType, cropCycleId } = useCropCycleScope();
  const onboardingState = useOnboardingState();

  const defaultView = useMemo(() => getDefaultViewForRole(userRole ?? null), [userRole]);
  const initialView = useMemo(
    () => (tenantId ? (getStoredView(tenantId) ?? defaultView) : defaultView),
    [tenantId, defaultView]
  );
  const [view, setView] = useState<DashboardViewType>(initialView);

  useEffect(() => {
    if (!tenantId) return;
    const stored = getStoredView(tenantId);
    const def = getDefaultViewForRole(userRole ?? null);
    setView(stored ?? def);
  }, [tenantId, userRole]);

  const handleViewChange = (v: DashboardViewType) => {
    setView(v);
    setStoredView(tenantId, v);
  };

  const { data: summary, isLoading, error } = useQuery({
    queryKey: ['dashboard', 'summary', tenantId ?? '', scopeType, cropCycleId ?? ''],
    queryFn: () =>
      scopeType === 'crop_cycle' && cropCycleId
        ? apiClient.getDashboardSummary({ scope_type: 'crop_cycle', scope_id: cropCycleId })
        : apiClient.getDashboardSummary(),
    enabled: !!tenantId,
    staleTime: 60 * 1000,
    gcTime: 5 * 60 * 1000,
  });

  useEffect(() => {
    if (error) {
      toast.error(getApiErrorMessage(error, 'Failed to load dashboard'));
    }
  }, [error]);

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
        <DashboardViewSelector value={view} onChange={handleViewChange} />
      </div>

      {userRole === 'tenant_admin' && <OnboardingPanel onboardingState={onboardingState} />}

      {isLoading && (
        <div className="flex justify-center py-12">
          <LoadingSpinner />
        </div>
      )}

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          <p>{getApiErrorMessage(error, 'Failed to load dashboard. Please try again.')}</p>
          {import.meta.env.DEV && (
            <details className="mt-2">
              <summary className="cursor-pointer font-medium">Details</summary>
              <pre className="mt-1 overflow-auto rounded bg-red-100 p-2 text-xs">
                {error && typeof error === 'object' && 'response' in error
                  ? JSON.stringify(
                      {
                        status: (error as { response?: { status?: number } }).response?.status,
                        data: (error as { response?: { data?: unknown } }).response?.data,
                      },
                      null,
                      2
                    )
                  : String(error)}
              </pre>
            </details>
          )}
        </div>
      )}

      {!isLoading && !error && summary && (
        <>
          {view === 'owner' && <OwnerLayout data={summary} />}
          {view === 'manager' && <ManagerLayout data={summary} />}
          {view === 'accountant' && <AccountantLayout data={summary} />}
        </>
      )}

      {!isLoading && !error && !summary && tenantId && (
        <div className="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600">
          No dashboard data available.
        </div>
      )}
    </div>
  );
}
