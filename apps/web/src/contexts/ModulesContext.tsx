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

export function ModulesProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const { data, isLoading: loading, error } = useTenantModulesQuery();

  const modules = data?.modules ?? [];

  const isModuleEnabled = useCallback(
    (key: string): boolean => {
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
