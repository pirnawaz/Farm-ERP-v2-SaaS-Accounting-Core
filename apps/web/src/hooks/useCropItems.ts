import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { cropItemsApi } from '../api/cropItems';
import type { CreateCropItemPayload } from '../types';

export function useCropItems() {
  return useQuery({
    queryKey: ['crop-items'],
    queryFn: () => cropItemsApi.list(),
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
  });
}

export function useCreateCropItem() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateCropItemPayload) => cropItemsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crop-items'] });
    },
  });
}

export function useUpdateCropItem() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Parameters<typeof cropItemsApi.update>[1];
    }) => cropItemsApi.update(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crop-items'] });
    },
  });
}
