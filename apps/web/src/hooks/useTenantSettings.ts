import { useTenantSettings as useTenantSettingsContext } from '../contexts/TenantSettingsContext';

/**
 * Hook to access tenant settings (currency, locale, timezone)
 * This is a re-export for convenience - the actual implementation is in the context
 */
export function useTenantSettings() {
  return useTenantSettingsContext();
}
