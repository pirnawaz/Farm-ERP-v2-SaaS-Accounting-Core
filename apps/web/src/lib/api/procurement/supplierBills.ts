import { apiClient } from '@farm-erp/shared';

export type SupplierBillStatus = 'DRAFT' | 'APPROVED' | 'CANCELLED' | 'POSTED' | 'PARTIALLY_PAID' | 'PAID';
export type SupplierBillPaymentTerms = 'CASH' | 'CREDIT';

export interface SupplierBillLine {
  id: string;
  tenant_id: string;
  supplier_bill_id: string;
  line_no: number;
  description?: string | null;
  qty: string | number;
  cash_unit_price: string | number;
  credit_unit_price?: string | number | null;
  base_cash_amount: string;
  selected_unit_price: string;
  credit_premium_amount: string;
  line_total: string;
}

export interface SupplierBill {
  id: string;
  tenant_id: string;
  supplier_id: string;
  reference_no?: string | null;
  bill_date: string;
  due_date?: string | null;
  currency_code: string;
  payment_terms: SupplierBillPaymentTerms;
  status: SupplierBillStatus;
  payment_status?: 'UNPAID' | 'PARTIALLY_PAID' | 'PAID';
  paid_amount?: string;
  outstanding_amount?: string;
  subtotal_cash_amount: string;
  credit_premium_total: string;
  grand_total: string;
  notes?: string | null;
  lines?: SupplierBillLine[];
  supplier?: { id: string; name: string };
}

export interface UpsertSupplierBillLinePayload {
  line_no?: number;
  description?: string | null;
  qty: number;
  cash_unit_price: number;
  credit_unit_price?: number | null;
}

export interface CreateSupplierBillPayload {
  supplier_id: string;
  reference_no?: string | null;
  bill_date: string;
  due_date?: string | null;
  currency_code?: string;
  payment_terms: SupplierBillPaymentTerms;
  notes?: string | null;
  lines: UpsertSupplierBillLinePayload[];
}

export interface UpdateSupplierBillPayload extends Omit<CreateSupplierBillPayload, 'supplier_id' | 'bill_date' | 'payment_terms'> {
  supplier_id?: string;
  bill_date?: string;
  payment_terms?: SupplierBillPaymentTerms;
}

export const supplierBillsApi = {
  list: (params?: { supplier_id?: string; status?: string }) => {
    const qs = new URLSearchParams();
    if (params?.supplier_id) qs.set('supplier_id', params.supplier_id);
    if (params?.status) qs.set('status', params.status);
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    return apiClient.get<SupplierBill[]>(`/api/supplier-bills${suffix}`);
  },
  get: (id: string) => apiClient.get<SupplierBill>(`/api/supplier-bills/${id}`),
  create: (payload: CreateSupplierBillPayload) => apiClient.post<SupplierBill>('/api/supplier-bills', payload),
  update: (id: string, payload: UpdateSupplierBillPayload) => apiClient.put<SupplierBill>(`/api/supplier-bills/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/supplier-bills/${id}`),
  post: (id: string, payload: { posting_date: string; idempotency_key?: string }) =>
    apiClient.post(`/api/supplier-bills/${id}/post`, payload),
};

