import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { journalsApi } from '../api/journals';
import type { StoreJournalPayload, UpdateJournalPayload, ReverseJournalPayload } from '../types';
import toast from 'react-hot-toast';

export function useJournalEntries(params: {
  from?: string;
  to?: string;
  status?: string;
  q?: string;
  limit?: number;
  offset?: number;
} = {}) {
  return useQuery({
    queryKey: ['journals', params],
    queryFn: () => journalsApi.list(params),
    staleTime: 30 * 1000,
  });
}

export function useJournalEntry(id: string | undefined) {
  return useQuery({
    queryKey: ['journals', id],
    queryFn: () => journalsApi.get(id!),
    enabled: !!id,
    staleTime: 10 * 1000,
  });
}

export function useCreateJournal() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: StoreJournalPayload) => journalsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journals'] });
      toast.success('Journal created');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to create journal');
    },
  });
}

export function useUpdateJournal(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: UpdateJournalPayload) => journalsApi.update(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journals'] });
      queryClient.invalidateQueries({ queryKey: ['journals', id] });
      toast.success('Journal updated');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to update journal');
    },
  });
}

export function usePostJournal(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => journalsApi.post(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journals'] });
      queryClient.invalidateQueries({ queryKey: ['journals', id] });
      toast.success('Journal posted');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to post journal');
    },
  });
}

export function useReverseJournal(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload?: ReverseJournalPayload) => journalsApi.reverse(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journals'] });
      queryClient.invalidateQueries({ queryKey: ['journals', id] });
      toast.success('Journal reversed');
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to reverse journal');
    },
  });
}
