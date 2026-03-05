import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { UpdateTenantModulesPayload } from '@farm-erp/shared';
import { modulesApi } from '../api/modules';
import { tenantAddonModulesApi } from '../api/tenantAddonModules';
import { useTenant } from './useTenant';

/** Tenant modules (GET /api/tenant/modules). Refetches when tenantId changes and after login. */
export function useTenantModulesQuery() {
  const { tenantId } = useTenant();
  return useQuery({
    queryKey: ['tenantModules', tenantId ?? ''],
    queryFn: () => modulesApi.getTenantModules(),
    staleTime: 5 * 60 * 1000,
    retry: 1,
  });
}

/** Addon expansion modules (orchards, livestock) per tenant. Used for sidebar gating. */
export function useTenantAddonModulesQuery() {
  return useQuery({
    queryKey: ['tenant', 'addonModules'],
    queryFn: () => tenantAddonModulesApi.getTenantAddonModules(),
    staleTime: 5 * 60 * 1000,
    retry: 1,
  });
}

/** Update addon module enabled state (tenant_admin). Invalidates addon modules query so sidebar updates. */
export function useUpdateTenantAddonModuleMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ moduleKey, isEnabled }: { moduleKey: 'orchards' | 'livestock'; isEnabled: boolean }) =>
      tenantAddonModulesApi.updateAddonModule(moduleKey, isEnabled),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tenant', 'addonModules'] });
    },
  });
}

export function useUpdateTenantModulesMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: UpdateTenantModulesPayload) =>
      modulesApi.updateTenantModules(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tenantModules'] });
    },
  });
}
