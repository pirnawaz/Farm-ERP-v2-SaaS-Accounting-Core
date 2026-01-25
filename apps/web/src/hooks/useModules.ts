import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { UpdateTenantModulesPayload } from '@farm-erp/shared';
import { modulesApi } from '../api/modules';

export function useTenantModulesQuery() {
  return useQuery({
    queryKey: ['tenantModules'],
    queryFn: () => modulesApi.getTenantModules(),
    staleTime: 5 * 60 * 1000,
    retry: 1,
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
