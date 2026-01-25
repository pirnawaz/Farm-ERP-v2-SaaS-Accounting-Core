import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { settlementApi } from '../api/settlement';
import type { PostSettlementRequest } from '../types';

export function useSettlementPreview() {
  return useMutation({
    mutationFn: ({ projectId, upToDate }: { projectId: string; upToDate?: string }) =>
      settlementApi.preview(projectId, upToDate),
  });
}

export function useSettlementOffsetPreview(projectId: string, postingDate: string | null, enabled: boolean = true) {
  return useQuery({
    queryKey: ['settlement-offset-preview', projectId, postingDate],
    queryFn: () => {
      if (!postingDate) throw new Error('Posting date is required');
      return settlementApi.offsetPreview(projectId, postingDate);
    },
    enabled: enabled && !!projectId && !!postingDate,
  });
}

export function usePostSettlement() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ projectId, payload }: { projectId: string; payload: PostSettlementRequest }) =>
      settlementApi.post(projectId, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['projects', variables.projectId] });
      queryClient.invalidateQueries({ queryKey: ['operational-transactions'] });
    },
  });
}
