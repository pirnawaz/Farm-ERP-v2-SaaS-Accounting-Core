import { apiClient } from '@farm-erp/shared';

export type SupplierBillLineMatch = {
  id: string;
  tenant_id: string;
  supplier_bill_line_id: string;
  purchase_order_line_id?: string | null;
  grn_line_id?: string | null;
  matched_qty: string;
  matched_amount: string;
  grnLine?: { id: string; grn_id: string; grn?: { id: string; doc_no: string } };
  purchaseOrderLine?: { id: string; purchase_order_id: string; purchaseOrder?: { id: string; po_no: string } };
};

export const supplierBillMatchesApi = {
  get: (supplierBillId: string) =>
    apiClient.get<{ supplier_bill_id: string; status: string; matches: SupplierBillLineMatch[] }>(
      `/api/supplier-bills/${supplierBillId}/matches`,
    ),
  sync: (
    supplierBillId: string,
    payload: {
      matches: Array<{
        supplier_bill_line_id: string;
        purchase_order_line_id?: string | null;
        grn_line_id?: string | null;
        matched_qty: number;
        matched_amount: number;
      }>;
    },
  ) => apiClient.put(`/api/supplier-bills/${supplierBillId}/matches`, payload),
};

