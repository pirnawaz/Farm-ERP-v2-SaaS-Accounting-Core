import { apiClient } from '@farm-erp/shared';
import type { 
  OperationalTransaction, 
  CreateOperationalTransactionPayload,
  UpdateOperationalTransactionPayload,
  PostTransactionRequest,
  PostingGroup
} from '../types';

export interface TransactionFilters {
  status?: string;
  crop_cycle_id?: string;
  project_id?: string;
  classification?: string;
  date_from?: string;
  date_to?: string;
}

export const operationalTransactionsApi = {
  list: (filters?: TransactionFilters) => {
    const params = new URLSearchParams();
    if (filters?.status) params.append('status', filters.status);
    if (filters?.crop_cycle_id) params.append('crop_cycle_id', filters.crop_cycle_id);
    if (filters?.project_id) params.append('project_id', filters.project_id);
    if (filters?.classification) params.append('classification', filters.classification);
    if (filters?.date_from) params.append('date_from', filters.date_from);
    if (filters?.date_to) params.append('date_to', filters.date_to);
    
    const query = params.toString() ? `?${params.toString()}` : '';
    return apiClient.get<OperationalTransaction[]>(`/api/operational-transactions${query}`);
  },
  get: (id: string) => apiClient.get<OperationalTransaction>(`/api/operational-transactions/${id}`),
  create: (payload: CreateOperationalTransactionPayload) => 
    apiClient.post<OperationalTransaction>('/api/operational-transactions', payload),
  update: (id: string, payload: UpdateOperationalTransactionPayload) => 
    apiClient.patch<OperationalTransaction>(`/api/operational-transactions/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/operational-transactions/${id}`),
  post: (id: string, payload: PostTransactionRequest) => 
    apiClient.post<PostingGroup>(`/api/operational-transactions/${id}/post`, payload),
};
