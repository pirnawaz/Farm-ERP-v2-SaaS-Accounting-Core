import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { platformApi } from '../api/platform';
import type { ImpersonationStatus } from '../types';

export const IMPERSONATION_QUERY_KEY = ['platform', 'impersonation'];

export function useImpersonationStatus(enabled: boolean) {
  return useQuery({
    queryKey: IMPERSONATION_QUERY_KEY,
    queryFn: () => platformApi.getImpersonationStatus(),
    enabled,
    staleTime: 30_000,
  });
}

export function useStartImpersonation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ tenantId, userId }: { tenantId: string; userId?: string }) =>
      platformApi.startImpersonation(tenantId, userId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: IMPERSONATION_QUERY_KEY });
    },
  });
}

export function useStopImpersonation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (targetTenantId?: string) => platformApi.stopImpersonation(targetTenantId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: IMPERSONATION_QUERY_KEY });
    },
  });
}

export function useImpersonation(enableWhenPlatformAdmin: boolean): {
  status: ImpersonationStatus | undefined;
  isLoading: boolean;
  isImpersonating: boolean;
  stop: (targetTenantId?: string) => Promise<unknown>;
} {
  const { data: status, isLoading } = useImpersonationStatus(enableWhenPlatformAdmin);
  const stopMutation = useStopImpersonation();

  return {
    status,
    isLoading,
    isImpersonating: Boolean(status?.impersonating),
    stop: (targetTenantId?: string) => stopMutation.mutateAsync(targetTenantId),
  };
}
