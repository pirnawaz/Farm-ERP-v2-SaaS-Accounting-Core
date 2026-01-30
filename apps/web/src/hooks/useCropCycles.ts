import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { cropCyclesApi } from '../api/cropCycles';
import type { CreateCropCyclePayload } from '../types';

export function useCropCycles() {
  return useQuery({
    queryKey: ['crop-cycles'],
    queryFn: () => cropCyclesApi.list(),
    staleTime: 10 * 60 * 1000, // 10 minutes - reference data
    gcTime: 30 * 60 * 1000,
  });
}

export function useCropCycle(id: string) {
  return useQuery({
    queryKey: ['crop-cycles', id],
    queryFn: () => cropCyclesApi.get(id),
    enabled: !!id,
    staleTime: 5 * 60 * 1000, // 5 minutes
    gcTime: 30 * 60 * 1000,
  });
}

export function useCreateCropCycle() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateCropCyclePayload) => cropCyclesApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crop-cycles'] });
    },
  });
}

export function useUpdateCropCycle() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<CreateCropCyclePayload> }) =>
      cropCyclesApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['crop-cycles'] });
      queryClient.invalidateQueries({ queryKey: ['crop-cycles', variables.id] });
    },
  });
}

export function useDeleteCropCycle() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => cropCyclesApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crop-cycles'] });
    },
  });
}

export function useClosePreviewCropCycle(id: string) {
  return useQuery({
    queryKey: ['crop-cycles', id, 'close-preview'],
    queryFn: () => cropCyclesApi.closePreview(id),
    enabled: !!id,
    staleTime: 0,
  });
}

export function useCloseCropCycle() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, note }: { id: string; note?: string }) => cropCyclesApi.close(id, { note }),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['crop-cycles'] });
      queryClient.invalidateQueries({ queryKey: ['crop-cycles', variables.id] });
      queryClient.invalidateQueries({ queryKey: ['crop-cycles', variables.id, 'close-preview'] });
    },
  });
}

export function useReopenCropCycle() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => cropCyclesApi.reopen(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['crop-cycles'] });
      queryClient.invalidateQueries({ queryKey: ['crop-cycles', id] });
    },
  });
}

export function useOpenCropCycle() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => cropCyclesApi.open(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['crop-cycles'] });
      queryClient.invalidateQueries({ queryKey: ['crop-cycles', id] });
    },
  });
}
