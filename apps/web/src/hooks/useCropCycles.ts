import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { cropCyclesApi } from '../api/cropCycles';
import type { CreateCropCyclePayload } from '../types';

export function useCropCycles() {
  return useQuery({
    queryKey: ['crop-cycles'],
    queryFn: () => cropCyclesApi.list(),
  });
}

export function useCropCycle(id: string) {
  return useQuery({
    queryKey: ['crop-cycles', id],
    queryFn: () => cropCyclesApi.get(id),
    enabled: !!id,
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

export function useCloseCropCycle() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => cropCyclesApi.close(id),
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
