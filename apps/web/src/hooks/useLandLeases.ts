import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { landLeasesApi } from '../api/landLeases';
import type {
  CreateLandLeasePayload,
  UpdateLandLeasePayload,
} from '@farm-erp/shared';

export function useLandLeases() {
  return useQuery({
    queryKey: ['land-leases'],
    queryFn: () => landLeasesApi.list(),
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}

export function useLandLease(id: string) {
  return useQuery({
    queryKey: ['land-leases', id],
    queryFn: () => landLeasesApi.get(id),
    enabled: !!id,
  });
}

export function useCreateLandLease() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateLandLeasePayload) =>
      landLeasesApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['land-leases'] });
    },
  });
}

export function useUpdateLandLease() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: UpdateLandLeasePayload;
    }) => landLeasesApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-leases'] });
      queryClient.invalidateQueries({ queryKey: ['land-leases', variables.id] });
    },
  });
}

export function useDeleteLandLease() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => landLeasesApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['land-leases'] });
    },
  });
}
