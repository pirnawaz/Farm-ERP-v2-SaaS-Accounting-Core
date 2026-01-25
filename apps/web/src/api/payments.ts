import { apiClient } from '@farm-erp/shared';
import type { 
  Payment, 
  CreatePaymentPayload,
  PostPaymentRequest,
  PostingGroup
} from '../types';

export interface PaymentFilters {
  status?: string;
  direction?: string;
  party_id?: string;
  date_from?: string;
  date_to?: string;
}

export const paymentsApi = {
  list: (filters?: PaymentFilters) => {
    const params = new URLSearchParams();
    if (filters?.status) params.append('status', filters.status);
    if (filters?.direction) params.append('direction', filters.direction);
    if (filters?.party_id) params.append('party_id', filters.party_id);
    if (filters?.date_from) params.append('date_from', filters.date_from);
    if (filters?.date_to) params.append('date_to', filters.date_to);
    
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<Payment[]>(`/api/payments${query}`);
  },
  get: (id: string) => apiClient.get<Payment>(`/api/payments/${id}`),
  create: (payload: CreatePaymentPayload) => 
    apiClient.post<Payment>('/api/payments', payload),
  update: (id: string, payload: CreatePaymentPayload) => 
    apiClient.put<Payment>(`/api/payments/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/payments/${id}`),
  post: (id: string, payload: PostPaymentRequest) => 
    apiClient.post<PostingGroup>(`/api/payments/${id}/post`, payload),
  getAllocationPreview: (partyId: string, amount: string, postingDate: string) => {
    const params = new URLSearchParams();
    params.append('party_id', partyId);
    params.append('amount', amount);
    params.append('posting_date', postingDate);
    return apiClient.get<import('../types').AllocationPreview>(`/api/payments/allocation-preview?${params.toString()}`);
  },
};
