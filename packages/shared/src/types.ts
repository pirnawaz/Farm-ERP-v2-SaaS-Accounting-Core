export interface Tenant {
  id: string
  name: string
  status: 'active' | 'suspended'
  created_at: string
}

export interface Project {
  id: string
  tenant_id: string
  name: string
  crop_cycle_id?: string
  created_at: string
}

export interface DailyBookEntry {
  id: string
  tenant_id: string
  project_id: string
  type: 'EXPENSE' | 'INCOME'
  status: 'DRAFT' | 'POSTED' | 'VOID'
  event_date: string
  description: string
  gross_amount: string | number
  currency_code: string
  created_at: string
}

export interface CropCycle {
  id: string
  tenant_id: string
  name: string
  start_date: string
  end_date: string
  status: 'OPEN' | 'CLOSED'
  created_at: string
}

export interface Account {
  id: string
  tenant_id: string
  code: string
  name: string
  type: 'ASSET' | 'LIABILITY' | 'EQUITY' | 'INCOME' | 'EXPENSE'
  created_at: string
}

export interface PostingGroup {
  id: string
  tenant_id: string
  project_id: string
  source_type: string
  source_id: string
  posting_date: string
  reversal_of_posting_group_id?: string | null
  correction_reason?: string | null
  created_at: string
  project?: Project
  allocation_rows?: AllocationRow[]
  ledger_entries?: LedgerEntry[]
  reversal_of?: PostingGroup | null
  reversals?: PostingGroup[]
}

export interface AllocationRow {
  id: string
  tenant_id: string
  posting_group_id: string
  project_id: string
  cost_type: string
  amount: string | number
  currency_code: string
  rule_version?: string | null
  rule_hash?: string | null
  rule_snapshot_json?: unknown | null
  created_at: string
}

export interface LedgerEntry {
  id: string
  tenant_id: string
  posting_group_id: string
  account_id: string
  debit: string | number
  credit: string | number
  currency_code: string
  created_at: string
  account?: Account
}

// Phase 6: Reporting types
export interface TrialBalanceRow {
  account_id: string
  account_code: string
  account_name: string
  account_type: 'ASSET' | 'LIABILITY' | 'EQUITY' | 'INCOME' | 'EXPENSE'
  currency_code: string
  total_debit: string
  total_credit: string
  net: string
}

export interface GeneralLedgerLine {
  posting_date: string
  posting_group_id: string
  source_type: string
  source_id: string
  reversal_of_posting_group_id?: string | null
  correction_reason?: string | null
  ledger_entry_id: string
  account_id: string
  account_code: string
  account_name: string
  account_type: 'ASSET' | 'LIABILITY' | 'EQUITY' | 'INCOME' | 'EXPENSE'
  currency_code: string
  debit: string
  credit: string
  net: string
}

export interface GeneralLedgerResponse {
  data: GeneralLedgerLine[]
  pagination: {
    page: number
    per_page: number
    total: number
    last_page: number
  }
}

export interface ProjectPLRow {
  project_id: string
  currency_code: string
  income: string
  expenses: string
  net_profit: string
}

export interface CropCyclePLRow {
  crop_cycle_id: string
  crop_cycle_name: string
  currency_code: string
  income: string
  expenses: string
  net_profit: string
}

export interface AccountBalanceRow {
  account_id: string
  account_code: string
  account_name: string
  account_type: 'ASSET' | 'LIABILITY' | 'EQUITY' | 'INCOME' | 'EXPENSE'
  currency_code: string
  debits: string
  credits: string
  balance: string
}

// Module toggles / feature flags
export type ModuleKey =
  | 'accounting_core'
  | 'projects_crop_cycles'
  | 'land'
  | 'treasury_payments'
  | 'treasury_advances'
  | 'ar_sales'
  | 'settlements'
  | 'reports'
  | 'inventory'
  | 'machinery'
  | 'loans'

export interface TenantModuleItem {
  key: string
  name: string
  description: string | null
  is_core: boolean
  sort_order: number
  enabled: boolean
  status: string
}

export interface UpdateTenantModulesPayload {
  modules: { key: string; enabled: boolean }[]
}

export interface TenantModulesResponse {
  modules: TenantModuleItem[]
}

// Reconciliation report (read-only confidence checks)
export type ReconciliationCheckStatus = 'PASS' | 'WARN' | 'FAIL'

export interface ReconciliationCheck {
  key: string
  title: string
  status: ReconciliationCheckStatus
  summary: string
  details: Record<string, unknown>
}

export interface ReconciliationResponse {
  checks: ReconciliationCheck[]
  generated_at: string
}
