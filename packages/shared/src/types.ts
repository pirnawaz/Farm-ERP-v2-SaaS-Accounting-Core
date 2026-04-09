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
  project_id?: string | null
  source_type: string
  source_id: string
  posting_date: string
  reversal_of_posting_group_id?: string | null
  correction_reason?: string | null
  created_at: string
  /** Transaction / document currency (ISO 4217). */
  currency_code?: string | null
  /** Functional / reporting currency (ISO 4217). */
  base_currency_code?: string | null
  /** Base units per one unit of transaction currency when applicable. */
  fx_rate?: string | number | null
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
  debit?: string | number
  credit?: string | number
  debit_amount?: string | number
  credit_amount?: string | number
  currency_code: string
  base_currency_code?: string | null
  fx_rate?: string | number | null
  debit_amount_base?: string | number | null
  credit_amount_base?: string | number | null
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

export interface TrialBalanceResponse {
  as_of: string
  rows: TrialBalanceRow[]
  totals: {
    total_debit: string
    total_credit: string
  }
  balanced: boolean
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

// Dashboard summary (read-only, single payload for all role views)
export interface DashboardScope {
  type: string
  id: string | null
  label: string
}

export interface DashboardFarm {
  active_crop_cycles_count: number
  open_projects_count: number
  harvests_this_cycle_count: number
  unposted_records_count: number
}

export interface DashboardMoney {
  cash_balance: number
  bank_balance: number
  receivables_total: number
  advances_outstanding_total: number
}

export interface DashboardProfit {
  profit_this_cycle: number | null
  profit_ytd: number
  best_project: { project_id: string; name: string; profit: number } | null
  cost_per_acre: number | null
}

export interface DashboardGovernance {
  settlements_pending_count: number
  cycles_closed_count: number
  locks_warning: Array<{ type: string; label: string; date: string }>
}

export interface DashboardAlert {
  severity: 'info' | 'warn' | 'critical'
  title: string
  detail: string
  action: { label: string; to: string }
}

export interface DashboardSummary {
  scope: DashboardScope
  farm: DashboardFarm
  money: DashboardMoney
  profit: DashboardProfit
  governance: DashboardGovernance
  alerts: DashboardAlert[]
}

/** Farm integrity metrics (internal, read-only, tenant_admin) */
export interface FarmIntegrity {
  activities_missing_production_unit: number
  harvest_without_sale: number
  sales_overdue_no_payment: number
  negative_inventory_items: number
  production_units_no_activity_last_30_days: number
  livestock_units_negative_headcount: number
}

/** Daily admin review counts from audit log (created/edited today; deletes not logged) */
export interface DailyAdminReview {
  records_created_today: number
  records_edited_today: number
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
/** Backend uses FINALIZED; FINAL kept for legacy responses. */
export type SettlementPackStatus = 'DRAFT' | 'FINALIZED' | 'VOID' | 'FINAL'

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

export interface SettlementPackVersionRow {
  version_no: number
  generated_at: string | null
  generated_by_user_id: string | null
  content_hash: string | null
  has_pdf: boolean
}

export interface SettlementPackApprovalRow {
  approver_user_id: string | null
  approver_role: string
  status: string
  approved_at: string | null
  rejected_at: string | null
}

export interface SettlementPackListItem {
  id: string
  project_id: string
  reference_no: string
  status: SettlementPackStatus
  as_of_date: string | null
  prepared_at: string | null
  finalized_at: string | null
  is_read_only: boolean
  project: { id: string; name: string } | null
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
  finalized_at?: string | null
  finalized_by_user_id?: string | null
  is_read_only?: boolean
  versions?: SettlementPackVersionRow[]
  approvals?: SettlementPackApprovalRow[]
  notes?: string | null
  as_of_date?: string | null
  prepared_at?: string | null
}

export interface SettlementPackRegisterPayload {
  register_rows: SettlementPackRegisterRow[]
  register_lines: unknown[]
  metrics: unknown
  content_hash: string | null
  as_of_date: string
}

export interface SettlementPackGenerateVersionResponse {
  settlement_pack_id: string
  version_no: number
  summary_json: SettlementPackSummary
}

export interface SettlementPackExportPdfResponse {
  pack_id: string
  version: number
  sha256_hex: string
  generated_at: string
}

// Loans (Phase 1B)
export type LoanAgreementStatus = 'DRAFT' | 'ACTIVE' | 'POSTED' | 'CLOSED'

export interface LoanAgreementListItem {
  id: string
  reference_no: string | null
  status: LoanAgreementStatus
  principal_amount: string | null
  currency_code: string
  start_date: string | null
  maturity_date: string | null
  project: { id: string; name: string } | null
  lender_party: { id: string; name: string } | null
  updated_at: string | null
}

export interface LoanAgreementDetail extends LoanAgreementListItem {
  tenant_id: string
  project_id: string
  lender_party_id: string | null
  interest_rate_annual: string | null
  notes: string | null
  created_at: string | null
}

export interface LoanStatementDrawdown {
  id: string
  drawdown_date: string | null
  posting_date: string
  amount: string
  reference_no: string | null
  posting_group_id: string | null
}

export interface LoanStatementRepayment {
  id: string
  repayment_date: string | null
  posting_date: string
  amount: string
  principal_amount: string
  interest_amount: string
  reference_no: string | null
  posting_group_id: string | null
}

export interface LoanStatementLine {
  kind: 'DRAWDOWN' | 'REPAYMENT'
  id: string
  date: string
  amount: string
  principal: string | null
  interest: string | null
  reference_no: string | null
  balance_after: string
}

export interface LoanAgreementStatement {
  loan_agreement_id: string
  currency_code: string
  from: string | null
  to: string | null
  opening_balance: string
  closing_balance: string
  drawdowns: LoanStatementDrawdown[]
  repayments: LoanStatementRepayment[]
  lines: LoanStatementLine[]
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

/** Fixed assets (Phase 2A) */
export interface FixedAssetBook {
  id: string
  tenant_id: string
  fixed_asset_id: string
  book_type: string
  asset_cost: string | number
  accumulated_depreciation: string | number
  carrying_amount: string | number
  last_depreciation_date?: string | null
}

export interface FixedAssetDisposal {
  id: string
  tenant_id: string
  fixed_asset_id: string
  disposal_date: string
  proceeds_amount: string | number
  proceeds_account?: 'BANK' | 'CASH' | null
  status: 'DRAFT' | 'POSTED'
  posting_date?: string | null
  posted_at?: string | null
  posting_group_id?: string | null
  carrying_amount_at_post?: string | number | null
  gain_amount?: string | number | null
  loss_amount?: string | number | null
  notes?: string | null
  posting_group?: PostingGroup | null
}

export interface FixedAsset {
  id: string
  tenant_id: string
  project_id?: string | null
  asset_code: string
  name: string
  category: string
  acquisition_date: string
  in_service_date?: string | null
  status: 'DRAFT' | 'ACTIVE' | 'DISPOSED' | 'RETIRED'
  currency_code: string
  acquisition_cost: string | number
  residual_value: string | number
  useful_life_months: number
  depreciation_method: string
  notes?: string | null
  activation_posting_group_id?: string | null
  activated_at?: string | null
  project?: Project | null
  activation_posting_group?: PostingGroup | null
  books?: FixedAssetBook[]
  disposals?: FixedAssetDisposal[]
}

export interface FixedAssetDepreciationLine {
  id: string
  tenant_id: string
  depreciation_run_id: string
  fixed_asset_id: string
  depreciation_amount: string | number
  opening_carrying_amount: string | number
  closing_carrying_amount: string | number
  depreciation_start: string
  depreciation_end: string
  fixed_asset?: FixedAsset
}

export interface FixedAssetDepreciationRun {
  id: string
  tenant_id: string
  reference_no: string
  status: 'DRAFT' | 'POSTED' | 'VOID'
  period_start: string
  period_end: string
  posting_date?: string | null
  posted_at?: string | null
  posting_group_id?: string | null
  lines?: FixedAssetDepreciationLine[]
  lines_count?: number
  posting_group?: PostingGroup | null
}

export type CreateFixedAssetPayload = {
  project_id?: string | null
  asset_code: string
  name: string
  category: string
  acquisition_date: string
  in_service_date?: string | null
  currency_code: string
  acquisition_cost: number
  residual_value?: number
  useful_life_months: number
  depreciation_method: 'STRAIGHT_LINE'
  notes?: string | null
}

/** Stored FX: base units per 1 quote unit (see API docs). */
export interface ExchangeRateRow {
  id: string
  tenant_id: string
  rate_date: string
  base_currency_code: string
  quote_currency_code: string
  rate: string | number
  source?: string | null
  created_by?: string | null
  created_at?: string
  updated_at?: string
}

export type CreateExchangeRatePayload = {
  rate_date: string
  base_currency_code: string
  quote_currency_code: string
  rate: number
  source?: string | null
}

export interface FxRevaluationLine {
  id: string
  tenant_id: string
  fx_revaluation_run_id: string
  source_type: string
  source_id: string
  currency_code: string
  original_base_amount: string | number
  revalued_base_amount: string | number
  delta_amount: string | number
  created_at?: string
  updated_at?: string
}

export interface FxRevaluationRun {
  id: string
  tenant_id: string
  reference_no: string
  status: 'DRAFT' | 'POSTED' | 'VOID'
  as_of_date: string
  posting_date?: string | null
  posting_group_id?: string | null
  posted_at?: string | null
  posted_by_user_id?: string | null
  created_at?: string
  updated_at?: string
  lines?: FxRevaluationLine[]
  lines_count?: number
  posting_group?: PostingGroup | null
}
