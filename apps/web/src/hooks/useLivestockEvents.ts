import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { livestockEventsApi, type LivestockEventFilters } from '../api/livestockEvents';
import type { CreateLivestockEventPayload, UpdateLivestockEventPayload } from '../types';

export function useLivestockEvents(params?: LivestockEventFilters) {
  return useQuery({
    queryKey: ['livestock-events', params],
    queryFn: () => livestockEventsApi.list(params),
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}

export function useLivestockEvent(id: string) {
  return useQuery({
    queryKey: ['livestock-events', id],
    queryFn: () => livestockEventsApi.get(id),
    enabled: !!id,
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}

export function useCreateLivestockEvent() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateLivestockEventPayload) => livestockEventsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['livestock-events'] });
    },
  });
}

export function useUpdateLivestockEvent() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateLivestockEventPayload }) =>
      livestockEventsApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['livestock-events'] });
      queryClient.invalidateQueries({ queryKey: ['livestock-events', variables.id] });
    },
  });
}

export function useDeleteLivestockEvent() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => livestockEventsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['livestock-events'] });
    },
  });
}
