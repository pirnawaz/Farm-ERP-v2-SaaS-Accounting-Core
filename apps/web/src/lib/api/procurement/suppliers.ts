import { apiClient } from '@farm-erp/shared';

export type SupplierStatus = 'ACTIVE' | 'INACTIVE';

export interface Supplier {
  id: string;
  tenant_id: string;
  name: string;
  status: SupplierStatus;
  party_id?: string | null;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  notes?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface CreateSupplierPayload {
  name: string;
  status?: SupplierStatus;
  party_id?: string | null;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  notes?: string | null;
}

export interface UpdateSupplierPayload extends Partial<CreateSupplierPayload> {}

export const suppliersApi = {
  list: () => apiClient.get<Supplier[]>('/api/suppliers'),
  get: (id: string) => apiClient.get<Supplier>(`/api/suppliers/${id}`),
  create: (payload: CreateSupplierPayload) => apiClient.post<Supplier>('/api/suppliers', payload),
  update: (id: string, payload: UpdateSupplierPayload) => apiClient.patch<Supplier>(`/api/suppliers/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/suppliers/${id}`),
};

