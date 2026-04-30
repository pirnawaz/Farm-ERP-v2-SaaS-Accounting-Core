import { apiClient } from '@farm-erp/shared';

export type SupplierInvoiceLinePoMatch = {
  id: string;
  tenant_id: string;
  supplier_invoice_line_id: string;
  purchase_order_line_id: string;
  matched_qty: string;
  matched_amount: string;
  purchaseOrderLine?: {
    id: string;
    purchase_order_id: string;
    line_no?: number | null;
    description?: string | null;
    purchaseOrder?: { id: string; po_no: string } | null;
  } | null;
};

export const supplierInvoicePoMatchesApi = {
  get: (supplierInvoiceId: string) =>
    apiClient.get<{ supplier_invoice_id: string; status: string; matches: SupplierInvoiceLinePoMatch[] }>(
      `/api/supplier-invoices/${supplierInvoiceId}/po-matches`,
    ),
  sync: (
    supplierInvoiceId: string,
    payload: {
      matches: Array<{
        supplier_invoice_line_id: string;
        purchase_order_line_id: string;
        matched_qty: number;
        matched_amount: number;
      }>;
    },
  ) => apiClient.put(`/api/supplier-invoices/${supplierInvoiceId}/po-matches`, payload),
};

