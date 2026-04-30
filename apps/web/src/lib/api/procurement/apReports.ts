import { apiClient } from '@farm-erp/shared';

export type SupplierLedgerRow = {
  date: string;
  type: 'INVOICE' | 'PAYMENT';
  reference: string | null;
  ref_id: string;
  debit: string;
  credit: string;
  running_balance: string;
};

export type SupplierLedgerResponse = {
  from: string | null;
  to: string | null;
  party_id: string;
  supplier_name: string;
  rows: SupplierLedgerRow[];
  totals: { total_debit: string; total_credit: string; ending_balance: string };
};

export type UnpaidBillRow = {
  supplier_invoice_id: string;
  reference_no: string | null;
  invoice_date: string | null;
  due_date: string | null;
  currency_code: string | null;
  status: string;
  total: string;
  paid: string;
  unpaid: string;
  party_id: string;
  supplier_name: string;
  crop_cycle_id: string | null;
  project_id: string | null;
};

export type ApAgingRow = {
  party_id: string;
  supplier_name: string;
  current: string;
  d1_30: string;
  d31_60: string;
  d61_90: string;
  d90_plus: string;
  total_outstanding: string;
};

export type ApAgingResponse = {
  as_of: string;
  rows: ApAgingRow[];
  totals: Record<string, string>;
};

export type CreditPremiumRow = {
  posting_date: string;
  crop_cycle_id: string | null;
  crop_cycle_name: string | null;
  project_id: string | null;
  project_name: string | null;
  party_id: string;
  supplier_name: string;
  supplier_invoice_id: string;
  supplier_invoice_line_id: string | null;
  line_no: number | null;
  description: string;
  credit_premium_amount: string;
};

export const apReportsApi = {
  supplierLedger: (params: { party_id: string; from?: string; to?: string }) => {
    const q = new URLSearchParams();
    q.append('party_id', params.party_id);
    if (params.from) q.append('from', params.from);
    if (params.to) q.append('to', params.to);
    return apiClient.get<SupplierLedgerResponse>(`/api/ap-reports/supplier-ledger?${q.toString()}`);
  },

  unpaidBills: (params: { party_id?: string; crop_cycle_id?: string; project_id?: string; as_of?: string } = {}) => {
    const q = new URLSearchParams();
    if (params.party_id) q.append('party_id', params.party_id);
    if (params.crop_cycle_id) q.append('crop_cycle_id', params.crop_cycle_id);
    if (params.project_id) q.append('project_id', params.project_id);
    if (params.as_of) q.append('as_of', params.as_of);
    return apiClient.get<{ rows: UnpaidBillRow[] }>(`/api/ap-reports/unpaid-bills?${q.toString()}`);
  },

  aging: (params: { as_of: string; party_id?: string }) => {
    const q = new URLSearchParams();
    q.append('as_of', params.as_of);
    if (params.party_id) q.append('party_id', params.party_id);
    return apiClient.get<ApAgingResponse>(`/api/ap-reports/aging?${q.toString()}`);
  },

  creditPremiumByProject: (params: {
    from?: string;
    to?: string;
    party_id?: string;
    crop_cycle_id?: string;
    project_id?: string;
  } = {}) => {
    const q = new URLSearchParams();
    if (params.from) q.append('from', params.from);
    if (params.to) q.append('to', params.to);
    if (params.party_id) q.append('party_id', params.party_id);
    if (params.crop_cycle_id) q.append('crop_cycle_id', params.crop_cycle_id);
    if (params.project_id) q.append('project_id', params.project_id);
    return apiClient.get<{ rows: CreditPremiumRow[] }>(`/api/ap-reports/credit-premium-by-project?${q.toString()}`);
  },
};

