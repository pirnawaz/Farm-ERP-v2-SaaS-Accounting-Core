import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { platformApi } from '../api/platform';
import type { ImpersonationStatusForUi } from '../types';

export const IMPERSONATION_QUERY_KEY = ['platform', 'impersonation'];
export const IMPERSONATION_STATUS_UI_QUERY_KEY = ['platform', 'impersonation', 'status'];

/** Use UI status endpoint (callable when impersonation cookie set, e.g. in tenant app). */
export function useImpersonationStatusForUi(enabled: boolean) {
  return useQuery({
    queryKey: IMPERSONATION_STATUS_UI_QUERY_KEY,
    queryFn: () => platformApi.getImpersonationStatusForUi(),
    enabled,
    staleTime: 30_000,
  });
}

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
      queryClient.invalidateQueries({ queryKey: IMPERSONATION_STATUS_UI_QUERY_KEY });
    },
  });
}

export function useForceStopImpersonation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => platformApi.forceStopImpersonation(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: IMPERSONATION_QUERY_KEY });
      queryClient.invalidateQueries({ queryKey: IMPERSONATION_STATUS_UI_QUERY_KEY });
    },
  });
}

export function useImpersonation(enableWhenInApp: boolean): {
  status: ImpersonationStatusForUi | undefined;
  isLoading: boolean;
  isImpersonating: boolean;
  isError: boolean;
  error: Error | null;
  stop: (targetTenantId?: string) => Promise<unknown>;
  forceStop: () => Promise<unknown>;
} {
  const { data: status, isLoading, isError, error } = useImpersonationStatusForUi(enableWhenInApp);
  const stopMutation = useStopImpersonation();
  const forceStopMutation = useForceStopImpersonation();

  return {
    status,
    isLoading,
    isImpersonating: Boolean(status?.is_impersonating),
    isError: Boolean(isError),
    error: error as Error | null,
    stop: (targetTenantId?: string) => stopMutation.mutateAsync(targetTenantId),
    forceStop: () => forceStopMutation.mutateAsync(),
  };
}
