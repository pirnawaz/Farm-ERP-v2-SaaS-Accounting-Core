import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { salesApi, type SaleFilters } from '../api/sales';
import type { Sale, CreateSalePayload, PostSaleRequest, ReverseSaleRequest } from '../types';
import toast from 'react-hot-toast';

export function useSales(filters?: SaleFilters) {
  return useQuery<Sale[], Error>({
    queryKey: ['sales', filters],
    queryFn: () => salesApi.list(filters),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
  });
}

export function useSale(id: string) {
  return useQuery({
    queryKey: ['sales', id],
    queryFn: () => salesApi.get(id),
    enabled: !!id,
  });
}

export function useCreateSale() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (payload: CreateSalePayload) => salesApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales'] });
      toast.success('Sale created successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to create sale');
    },
  });
}

export function useUpdateSale() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<CreateSalePayload> }) => 
      salesApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['sales'] });
      queryClient.invalidateQueries({ queryKey: ['sales', variables.id] });
      toast.success('Sale updated successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to update sale');
    },
  });
}

export function useDeleteSale() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id: string) => salesApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales'] });
      toast.success('Sale deleted successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to delete sale');
    },
  });
}

export function usePostSale() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostSaleRequest }) => 
      salesApi.post(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['sales'] });
      queryClient.invalidateQueries({ queryKey: ['sales', variables.id] });
      queryClient.invalidateQueries({ queryKey: ['parties'] });
      toast.success('Sale posted successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to post sale');
    },
  });
}

export function useReverseSale() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseSaleRequest }) => 
      salesApi.reverse(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['sales'] });
      queryClient.invalidateQueries({ queryKey: ['sales', variables.id] });
      queryClient.invalidateQueries({ queryKey: ['parties'] });
      toast.success('Sale reversed successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to reverse sale');
    },
  });
}
