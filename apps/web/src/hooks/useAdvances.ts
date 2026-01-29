import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { advancesApi, type AdvanceFilters } from '../api/advances';
import type { CreateAdvancePayload, PostAdvanceRequest } from '../types';
import toast from 'react-hot-toast';

export function useAdvances(filters?: AdvanceFilters) {
  return useQuery({
    queryKey: ['advances', filters],
    queryFn: () => advancesApi.list(filters),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function useAdvance(id: string) {
  return useQuery({
    queryKey: ['advances', id],
    queryFn: () => advancesApi.get(id),
    enabled: !!id,
  });
}

export function useCreateAdvance() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateAdvancePayload) => advancesApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['advances'] });
      toast.success('Advance created successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to create advance');
    },
  });
}

export function useUpdateAdvance() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<CreateAdvancePayload> }) => 
      advancesApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['advances'] });
      queryClient.invalidateQueries({ queryKey: ['advances', variables.id] });
      toast.success('Advance updated successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to update advance');
    },
  });
}

export function useDeleteAdvance() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => advancesApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['advances'] });
      toast.success('Advance deleted successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to delete advance');
    },
  });
}

export function usePostAdvance() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostAdvanceRequest }) => 
      advancesApi.post(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['advances'] });
      queryClient.invalidateQueries({ queryKey: ['advances', variables.id] });
      queryClient.invalidateQueries({ queryKey: ['parties'] });
      queryClient.invalidateQueries({ queryKey: ['party-balances'] });
      toast.success('Advance posted successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to post advance');
    },
  });
}
