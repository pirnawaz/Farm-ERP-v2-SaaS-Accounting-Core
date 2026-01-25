import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { tenantUsersApi } from '../api/tenantUsers';
import type { CreateTenantUserPayload, UpdateTenantUserPayload } from '../types';

export function useTenantUsers() {
  return useQuery({
    queryKey: ['tenantUsers'],
    queryFn: () => tenantUsersApi.list(),
  });
}

export function useCreateTenantUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateTenantUserPayload) => tenantUsersApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tenantUsers'] });
    },
  });
}

export function useUpdateTenantUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateTenantUserPayload }) =>
      tenantUsersApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['tenantUsers'] });
      queryClient.invalidateQueries({ queryKey: ['tenantUsers', variables.id] });
    },
  });
}

export function useDisableTenantUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => tenantUsersApi.disable(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tenantUsers'] });
    },
  });
}
