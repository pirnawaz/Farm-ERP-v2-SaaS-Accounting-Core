import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { bankReconciliationApi } from '../api/bankReconciliation';
import type {
  CreateBankReconciliationPayload,
  ClearBankEntriesPayload,
  UnclearBankEntriesPayload,
  AddStatementLinePayload,
} from '../types';
import toast from 'react-hot-toast';

export function useBankReconciliations(accountCode?: string) {
  return useQuery({
    queryKey: ['bank-reconciliations', accountCode ?? 'all'],
    queryFn: () => bankReconciliationApi.list({ account_code: accountCode || undefined, limit: 50 }),
    staleTime: 30 * 1000,
  });
}

export function useBankReconciliation(id: string | undefined) {
  return useQuery({
    queryKey: ['bank-reconciliations', id],
    queryFn: () => bankReconciliationApi.get(id!),
    enabled: !!id,
    staleTime: 10 * 1000,
  });
}

export function useCreateBankReconciliation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateBankReconciliationPayload) => bankReconciliationApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliations'] });
      toast.success('Reconciliation created');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to create reconciliation');
    },
  });
}

export function useClearBankEntries(reconciliationId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ClearBankEntriesPayload) =>
      bankReconciliationApi.clear(reconciliationId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliations'] });
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliations', reconciliationId] });
      toast.success('Entries cleared');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to clear entries');
    },
  });
}

export function useUnclearBankEntries(reconciliationId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: UnclearBankEntriesPayload) =>
      bankReconciliationApi.unclear(reconciliationId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliations'] });
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliations', reconciliationId] });
      toast.success('Entries uncleared');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to unclear entries');
    },
  });
}

export function useFinalizeBankReconciliation(reconciliationId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => bankReconciliationApi.finalize(reconciliationId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliations'] });
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliations', reconciliationId] });
      toast.success('Reconciliation finalized');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to finalize');
    },
  });
}

function invalidateReport(queryClient: ReturnType<typeof useQueryClient>, reconciliationId: string) {
  queryClient.invalidateQueries({ queryKey: ['bank-reconciliations'] });
  queryClient.invalidateQueries({ queryKey: ['bank-reconciliations', reconciliationId] });
}

export function useAddStatementLine(reconciliationId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: AddStatementLinePayload) =>
      bankReconciliationApi.addStatementLine(reconciliationId, payload),
    onSuccess: () => {
      invalidateReport(queryClient, reconciliationId);
      toast.success('Statement line added');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to add statement line');
    },
  });
}

export function useVoidStatementLine(reconciliationId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (lineId: string) =>
      bankReconciliationApi.voidStatementLine(reconciliationId, lineId),
    onSuccess: () => {
      invalidateReport(queryClient, reconciliationId);
      toast.success('Statement line voided');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to void statement line');
    },
  });
}

export function useMatchStatementLine(reconciliationId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ lineId, ledger_entry_id }: { lineId: string; ledger_entry_id: string }) =>
      bankReconciliationApi.matchStatementLine(reconciliationId, lineId, { ledger_entry_id }),
    onSuccess: () => {
      invalidateReport(queryClient, reconciliationId);
      toast.success('Statement line matched');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to match');
    },
  });
}

export function useUnmatchStatementLine(reconciliationId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (lineId: string) =>
      bankReconciliationApi.unmatchStatementLine(reconciliationId, lineId),
    onSuccess: () => {
      invalidateReport(queryClient, reconciliationId);
      toast.success('Match removed');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to unmatch');
    },
  });
}
