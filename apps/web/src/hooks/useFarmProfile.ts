import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { farmProfileApi } from '../api/farmProfile';
import type { UpdateFarmProfilePayload } from '../types';

export function useFarmProfile() {
  return useQuery({
    queryKey: ['farmProfile'],
    queryFn: () => farmProfileApi.get(),
  });
}

export function useCreateFarmProfileMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload?: UpdateFarmProfilePayload) => farmProfileApi.create(payload ?? {}),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['farmProfile'] });
    },
  });
}

export function useUpdateFarmProfileMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: UpdateFarmProfilePayload) => farmProfileApi.update(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['farmProfile'] });
    },
  });
}
