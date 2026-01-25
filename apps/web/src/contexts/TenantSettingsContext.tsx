import { createContext, useContext, ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { settingsApi, type TenantSettings } from '../api/settings';

interface TenantSettingsContextType {
  settings: TenantSettings | null;
  loading: boolean;
  error: Error | null;
  refresh: () => void;
}

const TenantSettingsContext = createContext<TenantSettingsContextType | undefined>(undefined);

export function TenantSettingsProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();

  const { data: settings, isLoading: loading, error } = useQuery({
    queryKey: ['tenantSettings'],
    queryFn: () => settingsApi.getTenantSettings(),
    staleTime: 5 * 60 * 1000, // Cache for 5 minutes
    retry: 1,
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['tenantSettings'] });
  };

  return (
    <TenantSettingsContext.Provider
      value={{
        settings: settings || null,
        loading,
        error: error as Error | null,
        refresh,
      }}
    >
      {children}
    </TenantSettingsContext.Provider>
  );
}

export function useTenantSettings() {
  const context = useContext(TenantSettingsContext);
  if (context === undefined) {
    throw new Error('useTenantSettings must be used within a TenantSettingsProvider');
  }
  return context;
}
