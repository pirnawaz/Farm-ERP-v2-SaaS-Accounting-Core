import { apiClient } from '@farm-erp/shared';

export type SettlementPackPhase1Kind = 'project' | 'crop_cycle';
export type IncludeRegister = 'none' | 'allocation' | 'ledger' | 'both';
export type RegisterOrder = 'date_asc' | 'date_desc';
export type Bucket = 'total' | 'month';

type MoneyStr = string;
type QtyStr = string;

export type SettlementAllocationRegisterRow = {
  posting_date: string;
  posting_group_id: string;
  source_type: string;
  source_id: string;
  crop_cycle_id: string | null;
  project_id: string | null;
  allocation_row_id: string;
  allocation_type: string;
  allocation_scope: string | null;
  party_id: string | null;
  amount: MoneyStr;
};

export type SettlementLedgerAuditRegisterRow = {
  posting_date: string;
  posting_group_id: string;
  source_type: string;
  source_id: string;
  crop_cycle_id: string | null;
  project_id: string;
  allocation_row_id: string;
  allocation_type: string;
  allocation_scope: string | null;
  party_id: string | null;
  ledger_entry_id: string;
  account_code: string;
  account_name: string;
  account_type: string;
  debit_amount: MoneyStr;
  credit_amount: MoneyStr;
};

export type SettlementPackPhase1Response = {
  scope: {
    tenant_id: string;
    kind: SettlementPackPhase1Kind;
    project_id?: string;
    crop_cycle_id?: string;
    project_ids?: string[];
  };
  period: {
    from: string;
    to: string;
    posting_date_axis: 'posting_groups.posting_date';
    bucket: Bucket;
  };
  currency_code: string;
  totals: {
    harvest_production: { qty: QtyStr | null; value: MoneyStr | null };
    ledger_revenue: {
      sales: MoneyStr;
      machinery_income: MoneyStr;
      in_kind_income: MoneyStr;
      total: MoneyStr;
    };
    costs: {
      inputs: MoneyStr;
      labour: MoneyStr;
      machinery: MoneyStr;
      credit_premium: MoneyStr;
      other: MoneyStr;
      total: MoneyStr;
    };
    advances: { advances: MoneyStr | null; recoveries: MoneyStr | null; net: MoneyStr | null };
    net: { net_ledger_result: MoneyStr; net_harvest_production_result: MoneyStr | null };
  };
  series_by_month?: {
    harvest_production: Array<{ month: string; qty: QtyStr; value: MoneyStr }>;
    credit_premium: Array<{ month: string; amount: MoneyStr }>;
    profitability: Array<{
      month: string;
      ledger_revenue_total: MoneyStr;
      cost_total: MoneyStr;
      inputs: MoneyStr;
      labour: MoneyStr;
      machinery: MoneyStr;
      other: MoneyStr;
    }>;
  };
  register: {
    allocation_rows?: { rows: SettlementAllocationRegisterRow[]; page: number; per_page: number; total_rows: number; capped: boolean };
    ledger_lines?: { rows: SettlementLedgerAuditRegisterRow[]; page: number; per_page: number; total_rows: number; capped: boolean };
  };
  exports: {
    csv: { summary_url: string; allocation_register_url: string; ledger_audit_register_url: string };
    pdf: { url: string };
  };
  _meta: Record<string, unknown>;
  projects_breakdown?: Array<Record<string, unknown>>;
};

export const settlementPackPhase1Api = {
  getProject: (params: {
    project_id: string;
    from: string;
    to: string;
    include_register?: IncludeRegister;
    allocation_page?: number;
    allocation_per_page?: number;
    ledger_page?: number;
    ledger_per_page?: number;
    register_order?: RegisterOrder;
    bucket?: Bucket;
  }) => {
    const qs = new URLSearchParams(params as any);
    return apiClient.get<SettlementPackPhase1Response>(`/api/reports/settlement-pack/project?${qs.toString()}`);
  },

  getCropCycle: (params: {
    crop_cycle_id: string;
    from: string;
    to: string;
    include_register?: IncludeRegister;
    allocation_page?: number;
    allocation_per_page?: number;
    ledger_page?: number;
    ledger_per_page?: number;
    register_order?: RegisterOrder;
    bucket?: Bucket;
    include_projects_breakdown?: boolean;
  }) => {
    const qs = new URLSearchParams(params as any);
    return apiClient.get<SettlementPackPhase1Response>(`/api/reports/settlement-pack/crop-cycle?${qs.toString()}`);
  },

  downloadCsv: (url: string) => apiClient.getBlob(url),
  downloadPdf: (url: string) => apiClient.getBlob(url),
};

