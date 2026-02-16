import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { accountingPeriodsApi } from '../api/accountingPeriods';
import type {
  StoreAccountingPeriodPayload,
  CloseAccountingPeriodPayload,
  ReopenAccountingPeriodPayload,
} from '../types';
import toast from 'react-hot-toast';

export function useAccountingPeriods(params: { from?: string; to?: string } = {}) {
  return useQuery({
    queryKey: ['accounting-periods', params],
    queryFn: () => accountingPeriodsApi.list(params),
    staleTime: 30 * 1000,
  });
}

export function useAccountingPeriodEvents(periodId: string | undefined) {
  return useQuery({
    queryKey: ['accounting-periods', periodId, 'events'],
    queryFn: () => accountingPeriodsApi.events(periodId!),
    enabled: !!periodId,
    staleTime: 10 * 1000,
  });
}

export function useCreateAccountingPeriod() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: StoreAccountingPeriodPayload) => accountingPeriodsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-periods'] });
      toast.success('Period created');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to create period');
    },
  });
}

export function useCloseAccountingPeriod(periodId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload?: CloseAccountingPeriodPayload) => accountingPeriodsApi.close(periodId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-periods'] });
      queryClient.invalidateQueries({ queryKey: ['accounting-periods', periodId, 'events'] });
      toast.success('Period closed');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to close period');
    },
  });
}

export function useReopenAccountingPeriod(periodId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload?: ReopenAccountingPeriodPayload) => accountingPeriodsApi.reopen(periodId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-periods'] });
      queryClient.invalidateQueries({ queryKey: ['accounting-periods', periodId, 'events'] });
      toast.success('Period reopened');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to reopen period');
    },
  });
}
