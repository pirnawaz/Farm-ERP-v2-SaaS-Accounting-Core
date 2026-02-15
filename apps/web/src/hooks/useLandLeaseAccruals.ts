import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { landLeaseAccrualsApi } from '../api/landLeaseAccruals';
import type {
  CreateLandLeaseAccrualPayload,
  UpdateLandLeaseAccrualPayload,
  PostLandLeaseAccrualPayload,
  ReverseLandLeaseAccrualPayload,
} from '@farm-erp/shared';

export function useLandLeaseAccruals(leaseId: string | undefined) {
  return useQuery({
    queryKey: ['land-lease-accruals', leaseId],
    queryFn: () =>
      landLeaseAccrualsApi.list(leaseId ? { lease_id: leaseId, per_page: 50 } : undefined),
    enabled: !!leaseId,
  });
}

export function useLandLeaseAccrual(id: string | undefined) {
  return useQuery({
    queryKey: ['land-lease-accruals', 'detail', id],
    queryFn: () => landLeaseAccrualsApi.get(id!),
    enabled: !!id,
  });
}

export function useCreateLandLeaseAccrual() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateLandLeaseAccrualPayload) =>
      landLeaseAccrualsApi.create(payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-lease-accruals', variables.lease_id] });
    },
  });
}

export function useUpdateLandLeaseAccrual() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
      leaseId: _leaseId,
    }: {
      id: string;
      payload: UpdateLandLeaseAccrualPayload;
      leaseId: string;
    }) => landLeaseAccrualsApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-lease-accruals', variables.leaseId] });
    },
  });
}

export function useDeleteLandLeaseAccrual() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, leaseId: _leaseId }: { id: string; leaseId: string }) =>
      landLeaseAccrualsApi.delete(id),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-lease-accruals', variables.leaseId] });
    },
  });
}

export function usePostLandLeaseAccrual() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
      leaseId: _leaseId,
    }: {
      id: string;
      payload: PostLandLeaseAccrualPayload;
      leaseId: string;
    }) => landLeaseAccrualsApi.post(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-lease-accruals', variables.leaseId] });
    },
  });
}

export function useReverseLandLeaseAccrual() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
      leaseId: _leaseId,
    }: {
      id: string;
      payload: ReverseLandLeaseAccrualPayload;
      leaseId: string;
    }) => landLeaseAccrualsApi.reverse(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-lease-accruals', variables.leaseId] });
    },
  });
}
