import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import type { CostCenter } from '../types';

export function useCostCenters(status?: string) {
  return useQuery({
    queryKey: ['cost-centers', status],
    queryFn: async () => {
      const q = status ? `?status=${encodeURIComponent(status)}` : '';
      return apiClient.get<CostCenter[]>(`/api/cost-centers${q}`);
    },
    staleTime: 5 * 60 * 1000,
  });
}
