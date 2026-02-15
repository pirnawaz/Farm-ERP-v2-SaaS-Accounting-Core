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

// Land Lease (Maqada) — lease master, no accounting
export type LandLeaseFrequency = 'MONTHLY'

export interface LandLease {
  id: string
  tenant_id: string
  project_id: string
  land_parcel_id: string
  landlord_party_id: string
  start_date: string
  end_date: string | null
  rent_amount: string
  frequency: LandLeaseFrequency
  notes: string | null
  created_by: string | null
  created_at: string
  updated_at: string
  project?: { id: string; name: string }
  land_parcel?: { id: string; name: string }
  landlord_party?: { id: string; name: string }
}

export interface CreateLandLeasePayload {
  project_id: string
  land_parcel_id: string
  landlord_party_id: string
  start_date: string
  end_date?: string | null
  rent_amount: number | string
  frequency: LandLeaseFrequency
  notes?: string | null
}

export interface UpdateLandLeasePayload {
  project_id?: string
  land_parcel_id?: string
  landlord_party_id?: string
  start_date?: string
  end_date?: string | null
  rent_amount?: number | string
  frequency?: LandLeaseFrequency
  notes?: string | null
}

// Land Lease Accruals (Sprint 2 — no accounting posting)
export type LandLeaseAccrualStatus = 'DRAFT' | 'POSTED'

export interface LandLeaseAccrual {
  id: string
  tenant_id: string
  lease_id: string
  project_id: string
  period_start: string
  period_end: string
  amount: string
  memo: string | null
  status: LandLeaseAccrualStatus
  posting_group_id: string | null
  posted_at: string | null
  posted_by: string | null
  reversal_posting_group_id: string | null
  reversed_at: string | null
  reversed_by: string | null
  reversal_reason: string | null
  created_at: string
  updated_at: string
  lease?: { id: string; project_id: string }
  project?: { id: string; name: string }
}

export interface CreateLandLeaseAccrualPayload {
  lease_id: string
  project_id: string
  period_start: string
  period_end: string
  amount: number | string
  memo?: string | null
}

export interface UpdateLandLeaseAccrualPayload {
  period_start?: string
  period_end?: string
  amount?: number | string
  memo?: string | null
}

export interface PostLandLeaseAccrualPayload {
  posting_date: string
}

export interface PostLandLeaseAccrualResponse {
  accrual: LandLeaseAccrual
  posting_group_id: string
  posting_group?: { id: string; posting_date: string; source_type: string; source_id: string }
}

export interface ReverseLandLeaseAccrualPayload {
  posting_date: string
  reason?: string | null
}

export interface ReverseLandLeaseAccrualResponse {
  accrual: LandLeaseAccrual
  reversal_posting_group_id: string
  reversal_posting_group?: { id: string; posting_date: string; source_type: string; source_id: string }
}

/** Landlord statement report (ledger-backed) */
export interface LandlordStatementLine {
  posting_date: string
  description: string
  source_type: string
  source_id: string
  posting_group_id: string
  debit: number
  credit: number
  running_balance: number
  lease_id?: string | null
  land_parcel_id?: string | null
  project_id?: string | null
}

export interface LandlordStatementResponse {
  party: { id: string; name: string }
  date_from: string
  date_to: string
  opening_balance: number
  closing_balance: number
  lines: LandlordStatementLine[]
}

// Module toggles / feature flags
export type ModuleKey =
  | 'accounting_core'
  | 'projects_crop_cycles'
  | 'land'
  | 'land_leases'
  | 'treasury_payments'
  | 'treasury_advances'
  | 'ar_sales'
  | 'settlements'
  | 'reports'
  | 'inventory'
  | 'labour'
  | 'machinery'
  | 'loans'
  | 'crop_ops'

export type ModuleTier = 'CORE' | 'CORE_ADJUNCT' | 'OPTIONAL'

export interface TenantModuleItem {
  key: string
  name: string
  description: string | null
  is_core: boolean
  tier: ModuleTier
  sort_order: number
  enabled: boolean
  status: string
  /** Enabled module keys that depend on this module (disable blocked if non-empty) */
  required_by: string[]
}

export interface UpdateTenantModulesPayload {
  modules: { key: string; enabled: boolean }[]
}

export interface TenantModulesResponse {
  modules: TenantModuleItem[]
  /** Optional: key -> display name for dependency labels */
  key_to_name?: Record<string, string>
}

/** Response from PUT /api/tenant/modules may include which modules were auto-enabled per requested key */
export interface TenantModulesUpdateResponse extends TenantModulesResponse {
  /** For each module key that was explicitly enabled, list of dependency keys that were auto-enabled */
  auto_enabled?: Record<string, string[]>
}

// Onboarding checklist (tenant_admin first-login flow)
export type OnboardingStepId =
  | 'farm_profile'
  | 'add_land_parcel'
  | 'create_crop_cycle'
  | 'create_first_project'
  | 'add_first_party'
  | 'post_first_transaction'

export interface OnboardingState {
  dismissed: boolean
  steps: Record<OnboardingStepId, boolean>
}

export interface OnboardingUpdatePayload {
  dismissed?: boolean
  steps?: Partial<Record<OnboardingStepId, boolean>>
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

// Settlement Pack (Governance Phase 1)
export type SettlementPackStatus = 'DRAFT' | 'FINAL'

export interface SettlementPackSummary {
  total_amount: string
  row_count: number
  by_allocation_type: Record<string, string>
}

export interface SettlementPackRegisterRow {
  posting_group_id: string
  posting_date: string
  source_type: string
  source_id: string
  allocation_row_id: string
  allocation_type: string
  amount: string
  party_id: string | null
}

export interface SettlementPackResponse {
  id: string
  tenant_id: string
  project_id: string
  project?: { id: string; name: string } | null
  generated_by_user_id: string | null
  generated_at: string
  status: SettlementPackStatus
  summary_json: SettlementPackSummary
  register_version: string
  register_row_count?: number
  register_rows?: SettlementPackRegisterRow[]
}

// Payment reversal (treasury)
export interface ReversePaymentPayload {
  posting_date: string
  reason?: string
}

export interface ReversePaymentResponse {
  reversal_posting_group_id: string
  reversal_posting_group: PostingGroup
}
