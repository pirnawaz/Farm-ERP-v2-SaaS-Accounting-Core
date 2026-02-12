import { createContext, useContext, useCallback, ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTenantModulesQuery } from '../hooks/useModules';
import type { TenantModuleItem } from '@farm-erp/shared';

interface ModulesContextType {
  modules: TenantModuleItem[];
  loading: boolean;
  error: Error | null;
  isModuleEnabled: (key: string) => boolean;
  refreshModules: () => void;
}

const ModulesContext = createContext<ModulesContextType | undefined>(undefined);

// TEMP (System Completion Phase): when set, all modules appear enabled and readiness is immediate for E2E.
const forceAllModules =
  import.meta.env.VITE_FORCE_ALL_MODULES_ENABLED === 'true' ||
  (typeof import.meta.env.VITE_FORCE_ALL_MODULES_ENABLED === 'string' &&
    import.meta.env.VITE_FORCE_ALL_MODULES_ENABLED.length > 0);

export function ModulesProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const { data, status, error } = useTenantModulesQuery();
  /** Initial load only: true when we have no data yet (status pending), not during background refetch. */
  const loading = forceAllModules ? false : status === 'pending';

  const modules = data?.modules ?? [];

  const isModuleEnabled = useCallback(
    (key: string): boolean => {
      // TEMP: force-all bypasses module gating; treat every module as enabled.
      if (forceAllModules) return true;
      const m = modules.find((m: TenantModuleItem) => m.key === key);
      return m?.enabled ?? false;
    },
    [modules]
  );

  const refreshModules = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ['tenantModules'] });
  }, [queryClient]);

  return (
    <ModulesContext.Provider
      value={{
        modules,
        loading,
        error: error as Error | null,
        isModuleEnabled,
        refreshModules,
      }}
    >
      {children}
    </ModulesContext.Provider>
  );
}

export function useModules() {
  const context = useContext(ModulesContext);
  if (context === undefined) {
    throw new Error('useModules must be used within a ModulesProvider');
  }
  return context;
}
