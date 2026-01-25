import { apiClient } from '@farm-erp/shared';
import type { Party, PartyBalanceSummary, PartyStatement, OpenSale } from '../types';

export const partiesApi = {
  list: () => apiClient.get<Party[]>('/api/parties'),
  get: (id: string) => apiClient.get<Party>(`/api/parties/${id}`),
  create: (payload: { name: string; party_types: string[] }) => 
    apiClient.post<Party>('/api/parties', payload),
  update: (id: string, payload: { name?: string; party_types?: string[] }) => 
    apiClient.put<Party>(`/api/parties/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/parties/${id}`),
  getBalances: (partyId: string, asOfDate?: string) => {
    const params = new URLSearchParams();
    if (asOfDate) params.append('as_of', asOfDate);
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<PartyBalanceSummary>(`/api/parties/${partyId}/balances${query}`);
  },
  getStatement: (partyId: string, from?: string, to?: string, groupBy?: 'cycle' | 'project') => {
    const params = new URLSearchParams();
    if (from) params.append('from', from);
    if (to) params.append('to', to);
    if (groupBy) params.append('group_by', groupBy);
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<PartyStatement>(`/api/parties/${partyId}/statement${query}`);
  },
  getOpenSales: (partyId: string, asOfDate?: string) => {
    const params = new URLSearchParams();
    if (asOfDate) params.append('as_of', asOfDate);
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<OpenSale[]>(`/api/parties/${partyId}/receivables/open-sales${query}`);
  },
};
