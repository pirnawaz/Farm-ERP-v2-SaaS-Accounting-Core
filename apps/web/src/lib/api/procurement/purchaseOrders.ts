import { apiClient } from '@farm-erp/shared';

export type PurchaseOrderStatus = 'DRAFT' | 'APPROVED' | 'PARTIALLY_RECEIVED' | 'RECEIVED' | 'CANCELLED';

export interface PurchaseOrderLine {
  id: string;
  tenant_id: string;
  purchase_order_id: string;
  line_no: number;
  item_id?: string | null;
  description?: string | null;
  qty_ordered: string;
  qty_overbill_tolerance: string;
  expected_unit_cost?: string | null;
  item?: { id: string; name: string };
}

export interface PurchaseOrder {
  id: string;
  tenant_id: string;
  supplier_id: string;
  po_no: string;
  po_date: string;
  status: PurchaseOrderStatus;
  notes?: string | null;
  approved_at?: string | null;
  approved_by?: string | null;
  supplier?: { id: string; name: string };
  lines?: PurchaseOrderLine[];
}

export interface UpsertPurchaseOrderLinePayload {
  line_no?: number;
  item_id?: string | null;
  description?: string | null;
  qty_ordered: number;
  qty_overbill_tolerance?: number;
  expected_unit_cost?: number | null;
}

export interface CreatePurchaseOrderPayload {
  supplier_id: string;
  po_no: string;
  po_date: string;
  notes?: string | null;
  lines?: UpsertPurchaseOrderLinePayload[];
}

export type UpdatePurchaseOrderPayload = Partial<Omit<CreatePurchaseOrderPayload, 'supplier_id'>> & {
  lines?: UpsertPurchaseOrderLinePayload[];
};

export type PurchaseOrderMatchingLine = {
  purchase_order_line_id: string;
  line_no: number;
  item_id: string | null;
  item_name: string | null;
  description: string | null;
  qty_ordered: string;
  qty_overbill_tolerance: string;
  qty_received: string;
  qty_billed: string;
  qty_remaining_to_bill: string;
};

export type PurchaseOrderMatchingResponse = {
  purchase_order: {
    id: string;
    po_no: string;
    po_date: string | null;
    status: PurchaseOrderStatus;
    supplier: { id: string; name: string } | null;
  };
  lines: PurchaseOrderMatchingLine[];
};

export type PrepareSupplierInvoiceFromPoResponse = {
  purchase_order_id: string;
  po_no: string;
  party_id: string;
  currency_code: string;
  lines: Array<{
    purchase_order_line_id: string;
    line_no: number;
    item_id: string | null;
    description: string | null;
    qty_ordered: string;
    qty_received: string;
    qty_invoiced: string;
    remaining_qty: string;
    unit_price: string;
  }>;
};

export const purchaseOrdersApi = {
  list: (params?: { status?: string; supplier_id?: string }) => {
    const qs = new URLSearchParams();
    if (params?.status) qs.set('status', params.status);
    if (params?.supplier_id) qs.set('supplier_id', params.supplier_id);
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    return apiClient.get<PurchaseOrder[]>(`/api/purchase-orders${suffix}`);
  },
  get: (id: string) => apiClient.get<PurchaseOrder>(`/api/purchase-orders/${id}`),
  create: (payload: CreatePurchaseOrderPayload) => apiClient.post<PurchaseOrder>('/api/purchase-orders', payload),
  update: (id: string, payload: UpdatePurchaseOrderPayload) => apiClient.put<PurchaseOrder>(`/api/purchase-orders/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/purchase-orders/${id}`),
  approve: (id: string) => apiClient.post<PurchaseOrder>(`/api/purchase-orders/${id}/approve`, {}),
  matching: (id: string) => apiClient.get<PurchaseOrderMatchingResponse>(`/api/purchase-orders/${id}/matching`),
  prepareInvoice: (id: string) =>
    apiClient.get<PrepareSupplierInvoiceFromPoResponse>(`/api/purchase-orders/${id}/prepare-invoice`),
};

