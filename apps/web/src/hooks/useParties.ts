import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { partiesApi } from '../api/parties';
import toast from 'react-hot-toast';

export function useParties() {
  return useQuery({
    queryKey: ['parties'],
    queryFn: () => partiesApi.list(),
  });
}

export function useParty(id: string) {
  return useQuery({
    queryKey: ['parties', id],
    queryFn: () => partiesApi.get(id),
    enabled: !!id,
  });
}

export function useCreateParty() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (payload: { name: string; party_types: string[] }) => 
      partiesApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parties'] });
      toast.success('Party created successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to create party');
    },
  });
}

export function useUpdateParty() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: { name?: string; party_types?: string[] } }) => 
      partiesApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['parties'] });
      queryClient.invalidateQueries({ queryKey: ['parties', variables.id] });
      toast.success('Party updated successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to update party');
    },
  });
}

export function useDeleteParty() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id: string) => partiesApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parties'] });
      toast.success('Party deleted successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.error || 'Failed to delete party');
    },
  });
}

export function usePartyBalanceSummary(partyId: string, asOfDate?: string) {
  return useQuery({
    queryKey: ['parties', partyId, 'balances', asOfDate],
    queryFn: () => partiesApi.getBalances(partyId, asOfDate),
    enabled: !!partyId,
  });
}

export function usePartyStatement(partyId: string, from?: string, to?: string, groupBy?: 'cycle' | 'project') {
  return useQuery({
    queryKey: ['parties', partyId, 'statement', from, to, groupBy],
    queryFn: () => partiesApi.getStatement(partyId, from, to, groupBy),
    enabled: !!partyId,
  });
}

export function usePartyOpenSales(partyId: string, asOfDate?: string) {
  return useQuery({
    queryKey: ['parties', partyId, 'open-sales', asOfDate],
    queryFn: () => partiesApi.getOpenSales(partyId, asOfDate),
    enabled: !!partyId,
  });
}
