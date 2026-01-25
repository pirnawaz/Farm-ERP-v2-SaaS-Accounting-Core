import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { operationalTransactionsApi, type TransactionFilters } from '../api/operationalTransactions';
import type {
  CreateOperationalTransactionPayload,
  UpdateOperationalTransactionPayload,
  PostTransactionRequest
} from '../types';

export function useOperationalTransactions(filters?: TransactionFilters) {
  return useQuery({
    queryKey: ['operational-transactions', filters],
    queryFn: () => operationalTransactionsApi.list(filters),
  });
}

export function useOperationalTransaction(id: string) {
  return useQuery({
    queryKey: ['operational-transactions', id],
    queryFn: () => operationalTransactionsApi.get(id),
    enabled: !!id,
  });
}

export function useCreateOperationalTransaction() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateOperationalTransactionPayload) => 
      operationalTransactionsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['operational-transactions'] });
    },
  });
}

export function useUpdateOperationalTransaction() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateOperationalTransactionPayload }) =>
      operationalTransactionsApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['operational-transactions'] });
      queryClient.invalidateQueries({ queryKey: ['operational-transactions', variables.id] });
    },
  });
}

export function useDeleteOperationalTransaction() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => operationalTransactionsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['operational-transactions'] });
    },
  });
}

export function usePostOperationalTransaction() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostTransactionRequest }) =>
      operationalTransactionsApi.post(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['operational-transactions'] });
      queryClient.invalidateQueries({ queryKey: ['operational-transactions', variables.id] });
    },
  });
}
