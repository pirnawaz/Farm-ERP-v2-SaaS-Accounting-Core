import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { platformApi } from '../api/platform';
import type { CreatePlatformTenantPayload, UpdatePlatformTenantPayload } from '../types';

export function usePlatformTenants() {
  return useQuery({
    queryKey: ['platformTenants'],
    queryFn: () => platformApi.listTenants(),
  });
}

export function usePlatformTenant(id: string | null) {
  return useQuery({
    queryKey: ['platformTenants', id],
    queryFn: () => platformApi.getTenant(id!),
    enabled: !!id,
  });
}

export function useCreatePlatformTenant() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreatePlatformTenantPayload) => platformApi.createTenant(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platformTenants'] });
    },
  });
}

export function useUpdatePlatformTenant() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdatePlatformTenantPayload }) =>
      platformApi.updateTenant(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['platformTenants'] });
      queryClient.invalidateQueries({ queryKey: ['platformTenants', variables.id] });
    },
  });
}
