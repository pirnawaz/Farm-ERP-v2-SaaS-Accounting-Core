import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { paymentsApi, type PaymentFilters } from '../api/payments';
import type { CreatePaymentPayload, PostPaymentRequest } from '../types';
import toast from 'react-hot-toast';

export function usePayments(filters?: PaymentFilters) {
  return useQuery({
    queryKey: ['payments', filters],
    queryFn: () => paymentsApi.list(filters),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function usePayment(id: string) {
  return useQuery({
    queryKey: ['payments', id],
    queryFn: () => paymentsApi.get(id),
    enabled: !!id,
  });
}

export function useCreatePayment() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (payload: CreatePaymentPayload) => paymentsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payments'] });
      toast.success('Payment created successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to create payment');
    },
  });
}

export function useUpdatePayment() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CreatePaymentPayload }) => 
      paymentsApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['payments'] });
      queryClient.invalidateQueries({ queryKey: ['payments', variables.id] });
      toast.success('Payment updated successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to update payment');
    },
  });
}

export function useDeletePayment() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id: string) => paymentsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payments'] });
      toast.success('Payment deleted successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to delete payment');
    },
  });
}

export function usePostPayment() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostPaymentRequest }) => 
      paymentsApi.post(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['payments'] });
      queryClient.invalidateQueries({ queryKey: ['payments', variables.id] });
      queryClient.invalidateQueries({ queryKey: ['parties'] });
      toast.success('Payment posted successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to post payment');
    },
  });
}
