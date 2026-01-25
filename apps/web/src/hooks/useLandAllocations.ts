import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { landAllocationsApi } from '../api/landAllocations';
import type { CreateLandAllocationPayload } from '../types';

export function useLandAllocations(cropCycleId?: string) {
  return useQuery({
    queryKey: ['land-allocations', cropCycleId],
    queryFn: () => landAllocationsApi.list(cropCycleId),
  });
}

export function useLandAllocation(id: string) {
  return useQuery({
    queryKey: ['land-allocations', id],
    queryFn: () => landAllocationsApi.get(id),
    enabled: !!id,
  });
}

export function useCreateLandAllocation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateLandAllocationPayload) => landAllocationsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['land-allocations'] });
      queryClient.invalidateQueries({ queryKey: ['land-parcels'] });
    },
  });
}

export function useUpdateLandAllocation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<CreateLandAllocationPayload> }) =>
      landAllocationsApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-allocations'] });
      queryClient.invalidateQueries({ queryKey: ['land-allocations', variables.id] });
      queryClient.invalidateQueries({ queryKey: ['land-parcels'] });
    },
  });
}

export function useDeleteLandAllocation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => landAllocationsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['land-allocations'] });
      queryClient.invalidateQueries({ queryKey: ['land-parcels'] });
    },
  });
}
