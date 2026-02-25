import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { productionUnitsApi } from '../api/productionUnits';
import type { CreateProductionUnitPayload } from '../types';

export function useProductionUnits(params?: { status?: string; type?: string; category?: string; orchard_crop?: string }) {
  return useQuery({
    queryKey: ['production-units', params],
    queryFn: () => productionUnitsApi.list(params),
    staleTime: 10 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
  });
}

export function useProductionUnit(id: string) {
  return useQuery({
    queryKey: ['production-units', id],
    queryFn: () => productionUnitsApi.get(id),
    enabled: !!id,
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
  });
}

export function useCreateProductionUnit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateProductionUnitPayload) => productionUnitsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['production-units'] });
    },
  });
}

export function useUpdateProductionUnit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<CreateProductionUnitPayload> }) =>
      productionUnitsApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['production-units'] });
      queryClient.invalidateQueries({ queryKey: ['production-units', variables.id] });
    },
  });
}
