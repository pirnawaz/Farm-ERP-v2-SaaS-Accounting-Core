// Auth & Role Types
export type UserRole = 'tenant_admin' | 'accountant' | 'operator' | 'platform_admin';

export interface AuthState {
  tenantId: string;
  authToken?: string;
  userRole: UserRole;
}

// User
export interface User {
  id: string;
  tenant_id: string;
  name: string;
  email: string;
  role: UserRole;
  is_enabled: boolean;
  created_at: string;
}

// Farm profile (1:1 per tenant)
export interface FarmProfile {
  id: string;
  tenant_id: string;
  farm_name: string;
  country?: string | null;
  address_line1?: string | null;
  address_line2?: string | null;
  city?: string | null;
  region?: string | null;
  postal_code?: string | null;
  phone?: string | null;
  created_at: string;
  updated_at: string;
}

export interface UpdateFarmProfilePayload {
  farm_name?: string;
  country?: string | null;
  address_line1?: string | null;
  address_line2?: string | null;
  city?: string | null;
  region?: string | null;
  postal_code?: string | null;
  phone?: string | null;
}

export interface CreateTenantUserPayload {
  name: string;
  email: string;
  password: string;
  role: UserRole;
}

export interface UpdateTenantUserPayload {
  name?: string;
  email?: string;
  role?: UserRole;
  is_enabled?: boolean;
}

// Platform (platform_admin)
export interface PlatformTenant {
  id: string;
  name: string;
  status: 'active' | 'suspended';
  currency_code: string;
  locale: string;
  timezone: string;
  created_at: string;
}

export interface CreatePlatformTenantPayload {
  name: string;
  country?: string | null;
  currency_code?: string;
  locale?: string;
  timezone?: string;
  initial_admin_email: string;
  initial_admin_password: string;
  initial_admin_name: string;
}

export interface UpdatePlatformTenantPayload {
  name?: string;
  status?: 'active' | 'suspended';
  currency_code?: string;
  locale?: string;
  timezone?: string;
}

// Party
export type PartyType = 'HARI' | 'KAMDAR' | 'VENDOR' | 'BUYER' | 'LENDER' | 'CONTRACTOR' | 'LANDLORD';

export interface Party {
  id: string;
  tenant_id: string;
  name: string;
  party_types: PartyType[];
  created_at: string;
}

// Land Parcel
export interface LandParcel {
  id: string;
  tenant_id: string;
  name: string;
  total_acres: string;
  notes?: string;
  created_at: string;
}

export interface LandDocument {
  id: string;
  land_parcel_id: string;
  file_path: string;
  description?: string;
  created_at: string;
}

export interface LandParcelDetail extends LandParcel {
  documents?: LandDocument[];
  allocations?: LandAllocation[];
  allocations_by_cycle?: {
    crop_cycle: CropCycle;
    total_allocated_acres: string;
    allocations: LandAllocation[];
  }[];
  remaining_acres?: string;
}

// Crop Cycle
export type CropCycleStatus = 'OPEN' | 'CLOSED';

export interface CropCycle {
  id: string;
  tenant_id: string;
  name: string;
  crop_type?: string;
  start_date: string;
  end_date?: string;
  status: CropCycleStatus;
  created_at: string;
}

// Land Allocation
export interface LandAllocation {
  id: string;
  tenant_id: string;
  crop_cycle_id: string;
  land_parcel_id: string;
  party_id: string;
  allocated_acres: string;
  created_at: string;
  crop_cycle?: CropCycle;
  land_parcel?: LandParcel;
  party?: Party;
  project?: Project;
}

// Project
export type ProjectStatus = 'ACTIVE' | 'CLOSED';

export interface Project {
  id: string;
  tenant_id: string;
  name: string;
  party_id: string;
  crop_cycle_id: string;
  land_allocation_id?: string;
  status: ProjectStatus;
  created_at: string;
  crop_cycle?: CropCycle;
  party?: Party;
  land_allocation?: LandAllocation;
}

// Project Rule
export type KamdariOrder = 'BEFORE_SPLIT' | 'AFTER_SPLIT';
export type PoolDefinition = 'REVENUE_MINUS_SHARED_COSTS';

export interface ProjectRule {
  project_id: string;
  profit_split_landlord_pct: string;
  profit_split_hari_pct: string;
  kamdari_pct: string;
  kamdar_party_id?: string;
  kamdari_order: KamdariOrder;
  pool_definition: PoolDefinition;
}

// Operational Transaction
export type TransactionType = 'INCOME' | 'EXPENSE';
export type TransactionStatus = 'DRAFT' | 'POSTED' | 'VOID';
export type TransactionClassification = 'SHARED' | 'HARI_ONLY' | 'FARM_OVERHEAD';

export interface OperationalTransaction {
  id: string;
  tenant_id: string;
  project_id?: string;
  crop_cycle_id?: string;
  type: TransactionType;
  status: TransactionStatus;
  transaction_date: string;
  amount: string;
  classification: TransactionClassification;
  created_by?: string;
  created_at: string;
  project?: Project;
  crop_cycle?: CropCycle;
}

export interface PostTransactionRequest {
  posting_date: string;
  idempotency_key: string;
}

export interface PostingGroup {
  id: string;
  tenant_id: string;
  posting_date: string;
  source_type: string;
  source_id: string;
  crop_cycle_id?: string;
  created_at: string;
}

// Settlement
export interface SettlementPreview {
  pool_revenue: string;
  shared_costs: string;
  pool_profit: string;
  kamdari_amount: string;
  landlord_gross: string;
  hari_gross: string;
  hari_only_deductions: string;
  hari_net: string;
}

export interface PostSettlementRequest {
  posting_date: string;
  up_to_date?: string;
  idempotency_key: string;
  apply_advance_offset?: boolean;
  advance_offset_amount?: number;
}

export interface SettlementOffsetPreview {
  hari_party_id: string;
  hari_payable_amount: number;
  outstanding_advance: number;
  suggested_offset: number;
  max_offset: number;
}

export interface SettlementPostResult {
  settlement_id: string;
  posting_group_id: string;
  settlement: Settlement;
  posting_group: PostingGroup;
}

export interface Settlement {
  id: string;
  tenant_id: string;
  project_id: string;
  posting_group_id: string;
  pool_revenue: string;
  shared_costs: string;
  pool_profit: string;
  kamdari_amount: string;
  landlord_share: string;
  hari_share: string;
  hari_only_deductions: string;
  created_at: string;
  offsets?: SettlementOffset[];
  // New fields for sales-based settlements (Phase 11)
  settlement_no?: string;
  share_rule_id?: string;
  crop_cycle_id?: string | null;
  from_date?: string | null;
  to_date?: string | null;
  basis_amount?: string;
  status?: 'DRAFT' | 'POSTED' | 'REVERSED';
  posting_date?: string | null;
  reversal_posting_group_id?: string | null;
  posted_at?: string | null;
  reversed_at?: string | null;
  created_by?: string | null;
  share_rule?: {
    id: string;
    name: string;
    basis: string;
  };
  crop_cycle?: {
    id: string;
    name: string;
  };
  lines?: Array<{
    id: string;
    party_id: string;
    role?: string | null;
    percentage: string;
    amount: string;
    party?: {
      id: string;
      name: string;
    };
  }>;
  sales?: Array<{
    id: string;
    sale_no?: string | null;
    posting_date: string;
  }>;
}

export interface SettlementOffset {
  id: string;
  tenant_id: string;
  settlement_id: string;
  party_id: string;
  posting_group_id: string;
  posting_date: string;
  offset_amount: string;
  created_at: string;
}

// Payment
export type PaymentDirection = 'IN' | 'OUT';
export type PaymentMethod = 'CASH' | 'BANK';
export type PaymentStatus = 'DRAFT' | 'POSTED';

export interface SalePaymentAllocation {
  id: string;
  sale_id: string;
  payment_id: string;
  posting_group_id: string;
  allocation_date: string;
  amount: string;
  sale?: Sale;
}

export interface Payment {
  id: string;
  tenant_id: string;
  party_id: string;
  direction: PaymentDirection;
  amount: string;
  payment_date: string;
  method: PaymentMethod;
  reference?: string;
  status: PaymentStatus;
  posting_group_id?: string;
  settlement_id?: string;
  notes?: string;
  purpose?: 'GENERAL' | 'WAGES';
  posted_at?: string;
  created_at: string;
  party?: Party;
  settlement?: Settlement;
  sale_allocations?: SalePaymentAllocation[];
}

export interface CreatePaymentPayload {
  direction: PaymentDirection;
  party_id: string;
  amount: string;
  payment_date: string;
  method: PaymentMethod;
  reference?: string;
  settlement_id?: string;
  notes?: string;
  purpose?: 'GENERAL' | 'WAGES';
}

export interface PostPaymentRequest {
  posting_date: string;
  idempotency_key: string;
  crop_cycle_id?: string;
  allocation_mode?: 'FIFO' | 'MANUAL';
  allocations?: Array<{
    sale_id: string;
    amount: string;
  }>;
}

// Labour (Workers, Work Logs, Payables)
export type LabWorkerType = 'HARI' | 'STAFF' | 'CONTRACT';
export type LabRateBasis = 'DAILY' | 'HOURLY' | 'PIECE';
export type LabWorkLogStatus = 'DRAFT' | 'POSTED' | 'REVERSED';

export interface LabWorker {
  id: string;
  tenant_id: string;
  worker_no?: string | null;
  name: string;
  worker_type: LabWorkerType;
  rate_basis: LabRateBasis;
  default_rate?: string | null;
  phone?: string | null;
  is_active: boolean;
  party_id?: string | null;
  created_at: string;
  updated_at: string;
  party?: Party;
  balance?: LabWorkerBalance;
}

export interface LabWorkerBalance {
  id: string;
  tenant_id: string;
  worker_id: string;
  payable_balance: string;
  created_at: string;
  updated_at: string;
}

export interface LabWorkLog {
  id: string;
  tenant_id: string;
  doc_no: string;
  worker_id: string;
  work_date: string;
  crop_cycle_id: string;
  project_id: string;
  activity_id?: string | null;
  rate_basis: LabRateBasis;
  units: string;
  rate: string;
  amount: string;
  notes?: string | null;
  status: LabWorkLogStatus;
  posting_date?: string | null;
  posting_group_id?: string | null;
  created_at: string;
  updated_at: string;
  worker?: LabWorker;
  crop_cycle?: CropCycle;
  project?: Project;
  posting_group?: PostingGroup;
}

export interface PayablesOutstandingRow {
  worker_id: string;
  worker_name: string;
  payable_balance: string;
  party_id: string | null;
}

export interface CreateLabWorkerPayload {
  name: string;
  worker_no?: string;
  worker_type?: LabWorkerType;
  rate_basis?: LabRateBasis;
  default_rate?: number;
  phone?: string;
  is_active?: boolean;
  create_party?: boolean;
}

export interface UpdateLabWorkerPayload {
  name?: string;
  worker_no?: string;
  worker_type?: LabWorkerType;
  rate_basis?: LabRateBasis;
  default_rate?: number;
  phone?: string;
  is_active?: boolean;
}

export interface CreateLabWorkLogPayload {
  doc_no: string;
  worker_id: string;
  work_date: string;
  crop_cycle_id: string;
  project_id: string;
  activity_id?: string;
  rate_basis: LabRateBasis;
  units: number;
  rate: number;
  notes?: string;
}

export interface UpdateLabWorkLogPayload {
  doc_no?: string;
  worker_id?: string;
  work_date?: string;
  crop_cycle_id?: string;
  project_id?: string;
  activity_id?: string | null;
  rate_basis?: LabRateBasis;
  units?: number;
  rate?: number;
  notes?: string | null;
}

export interface PostLabWorkLogRequest {
  posting_date: string;
  idempotency_key?: string;
}

export interface ReverseLabWorkLogRequest {
  posting_date: string;
  reason: string;
}

// Crop Ops / Activities
export interface CropActivityType {
  id: string;
  tenant_id: string;
  name: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface CreateActivityTypePayload {
  name: string;
  is_active?: boolean;
}

export interface UpdateActivityTypePayload {
  name?: string;
  is_active?: boolean;
}

export interface CropActivityInput {
  id: string;
  activity_id: string;
  store_id: string;
  item_id: string;
  qty: string;
  unit_cost_snapshot?: string | null;
  line_total?: string | null;
  store?: InvStore;
  item?: InvItem;
}

export interface CropActivityLabour {
  id: string;
  activity_id: string;
  worker_id: string;
  rate_basis?: string | null;
  units: string;
  rate: string;
  amount?: string | null;
  worker?: LabWorker;
}

export type CropActivityStatus = 'DRAFT' | 'POSTED' | 'REVERSED';

export interface CropActivity {
  id: string;
  tenant_id: string;
  doc_no: string;
  activity_type_id: string;
  activity_date: string;
  crop_cycle_id: string;
  project_id: string;
  land_parcel_id?: string | null;
  notes?: string | null;
  status: CropActivityStatus;
  posting_date?: string | null;
  posting_group_id?: string | null;
  posted_at?: string | null;
  reversed_at?: string | null;
  created_at: string;
  updated_at: string;
  type?: CropActivityType;
  crop_cycle?: CropCycle;
  project?: Project;
  land_parcel?: LandParcel | null;
  inputs?: CropActivityInput[];
  labour?: CropActivityLabour[];
  posting_group?: PostingGroup;
}

export interface CreateCropActivityPayload {
  doc_no: string;
  activity_type_id: string;
  activity_date: string;
  crop_cycle_id: string;
  project_id: string;
  land_parcel_id?: string | null;
  notes?: string | null;
  inputs?: { store_id: string; item_id: string; qty: number }[];
  labour?: { worker_id: string; rate_basis?: string; units: number; rate: number }[];
}

export interface UpdateCropActivityPayload {
  doc_no?: string;
  activity_type_id?: string;
  activity_date?: string;
  crop_cycle_id?: string;
  project_id?: string;
  land_parcel_id?: string | null;
  notes?: string | null;
  inputs?: { store_id: string; item_id: string; qty: number }[];
  labour?: { worker_id: string; rate_basis?: string; units: number; rate: number }[];
}

export interface PostCropActivityRequest {
  posting_date: string;
  idempotency_key?: string;
}

export interface ReverseCropActivityRequest {
  posting_date: string;
  reason?: string;
  idempotency_key?: string;
}

// Harvest
export type HarvestStatus = 'DRAFT' | 'POSTED' | 'REVERSED';

export interface HarvestLine {
  id: string;
  tenant_id: string;
  harvest_id: string;
  inventory_item_id: string;
  store_id: string;
  quantity: string;
  uom?: string | null;
  notes?: string | null;
  created_at: string;
  updated_at: string;
  item?: InvItem;
  store?: InvStore;
}

export interface Harvest {
  id: string;
  tenant_id: string;
  harvest_no?: string | null;
  crop_cycle_id: string;
  land_parcel_id?: string | null;
  harvest_date: string;
  posting_date?: string | null;
  status: HarvestStatus;
  notes?: string | null;
  posted_at?: string | null;
  reversed_at?: string | null;
  posting_group_id?: string | null;
  reversal_posting_group_id?: string | null;
  created_at: string;
  updated_at: string;
  crop_cycle?: CropCycle;
  land_parcel?: LandParcel | null;
  posting_group?: PostingGroup;
  reversal_posting_group?: PostingGroup;
  lines?: HarvestLine[];
}

export interface CreateHarvestPayload {
  harvest_no?: string | null;
  crop_cycle_id: string;
  land_parcel_id?: string | null;
  harvest_date: string;
  notes?: string | null;
}

export interface UpdateHarvestPayload {
  harvest_no?: string | null;
  land_parcel_id?: string | null;
  harvest_date?: string;
  notes?: string | null;
}

export interface PostHarvestPayload {
  posting_date: string;
}

export interface ReverseHarvestPayload {
  reversal_date: string;
  reason?: string;
}

export type AdvanceType = 'HARI_ADVANCE' | 'VENDOR_ADVANCE' | 'LOAN';
export type AdvanceDirection = 'OUT' | 'IN';
export type AdvanceStatus = 'DRAFT' | 'POSTED';
export type AdvanceMethod = 'CASH' | 'BANK';

export interface Advance {
  id: string;
  tenant_id: string;
  party_id: string;
  type: AdvanceType;
  direction: AdvanceDirection;
  amount: string;
  posting_date: string;
  method: AdvanceMethod;
  status: AdvanceStatus;
  posting_group_id?: string;
  posted_at?: string;
  project_id?: string;
  crop_cycle_id?: string;
  notes?: string;
  idempotency_key?: string;
  created_at: string;
  party?: Party;
  project?: Project;
  crop_cycle?: CropCycle;
  posting_group?: PostingGroup;
}

export interface CreateAdvancePayload {
  party_id: string;
  type: AdvanceType;
  direction: AdvanceDirection;
  amount: string;
  posting_date: string;
  method: AdvanceMethod;
  project_id?: string;
  crop_cycle_id?: string;
  notes?: string;
}

export interface PostAdvanceRequest {
  posting_date: string;
  idempotency_key: string;
  crop_cycle_id?: string;
}

export type SaleStatus = 'DRAFT' | 'POSTED';

export interface SaleLine {
  id?: string;
  sale_id?: string;
  inventory_item_id: string;
  store_id?: string;
  quantity: string;
  uom?: string;
  unit_price: string;
  line_total: string;
  item?: InvItem;
  store?: InvStore;
}

export interface SaleInventoryAllocation {
  id: string;
  sale_id: string;
  sale_line_id: string;
  inventory_item_id: string;
  crop_cycle_id?: string;
  store_id: string;
  quantity: string;
  unit_cost: string;
  total_cost: string;
  costing_method: string;
}

export interface Sale {
  id: string;
  tenant_id: string;
  buyer_party_id: string;
  project_id?: string;
  crop_cycle_id?: string;
  amount: string;
  posting_date: string;
  sale_no?: string;
  sale_date?: string;
  due_date?: string;
  status: SaleStatus;
  posting_group_id?: string;
  posted_at?: string;
  reversed_at?: string;
  reversal_posting_group_id?: string;
  notes?: string;
  idempotency_key?: string;
  created_at: string;
  buyer_party?: Party;
  project?: Project;
  crop_cycle?: CropCycle;
  posting_group?: PostingGroup;
  lines?: SaleLine[];
  inventory_allocations?: SaleInventoryAllocation[];
}

export interface CreateSalePayload {
  buyer_party_id: string;
  project_id?: string;
  crop_cycle_id?: string;
  amount: string;
  posting_date: string;
  sale_no?: string;
  sale_date?: string;
  due_date?: string;
  notes?: string;
  sale_lines?: SaleLine[];
}

export interface PostSaleRequest {
  posting_date: string;
  idempotency_key: string;
}

export interface ReverseSaleRequest {
  reversal_date: string;
  reason?: string;
}

export interface PartyBalanceSummary {
  party: Party;
  allocated_payable_total: string;
  paid_total: string;
  outstanding_total: string;
  advance_balance_disbursed?: string;
  advance_balance_repaid?: string;
  advance_balance_outstanding?: string;
  receivable_balance?: string;
  receivable_sales_total?: string;
  receivable_payments_in_total?: string;
  allocations: Array<{
    posting_date: string;
    amount: string;
    allocation_type: string;
    project_id: string;
    project_name: string;
  }>;
  payments: Array<{
    id: string;
    payment_date: string;
    amount: string;
    direction: PaymentDirection;
    status: PaymentStatus;
  }>;
}

export interface PartyStatementLine {
  date: string;
  type: 'ALLOCATION' | 'PAYMENT' | 'ADVANCE' | 'SALE' | 'SETTLEMENT_OFFSET' | 'AR_ALLOCATION';
  reference: string;
  description: string;
  amount: string;
  direction: '+' | '-';
  running_balance?: string;
}

export interface OpenSale {
  sale_id: string;
  sale_no?: string;
  posting_date: string;
  sale_date: string;
  due_date: string;
  amount: string;
  allocated: string;
  outstanding: string;
}

export interface AllocationPreview {
  total_receivable: string;
  payment_amount: string;
  open_sales: OpenSale[];
  suggested_allocations: Array<{
    sale_id: string;
    sale_no?: string;
    posting_date: string;
    due_date: string;
    outstanding: string;
    amount: string;
  }>;
  unallocated_amount: string;
}

export interface ARAgeingReport {
  as_of: string;
  buckets: string[];
  rows: Array<{
    buyer_party_id: string;
    buyer_name: string;
    total_outstanding: string;
    bucket_0_30: string;
    bucket_31_60: string;
    bucket_61_90: string;
    bucket_90_plus: string;
  }>;
  totals: {
    total_outstanding: string;
    bucket_0_30: string;
    bucket_31_60: string;
    bucket_61_90: string;
    bucket_90_plus: string;
  };
}

export interface PartyStatementGroup {
  crop_cycle_id?: string;
  crop_cycle_name?: string;
  project_id?: string;
  project_name?: string;
  total_allocations: string;
  total_payments_out: string;
  total_payments_in: string;
  net_outstanding: string;
  projects?: PartyStatementGroup[];
}

export interface PartyStatement {
  party_id: string;
  from: string;
  to: string;
  summary: {
    total_allocations_increasing_balance: string;
    total_allocations_decreasing_balance: string;
    total_payments_out: string;
    total_payments_in: string;
    unassigned_payments_total: string;
    total_advances_out?: string;
    total_advances_in?: string;
    closing_balance_payable: string;
    closing_balance_receivable: string;
    closing_balance_advance?: string;
  };
  grouped_breakdown: PartyStatementGroup[];
  line_items: PartyStatementLine[];
}

// Reports
export interface TrialBalanceRow {
  account_id: string;
  account_code: string;
  account_name: string;
  account_type: string;
  currency_code: string;
  total_debit: string;
  total_credit: string;
  net: string;
}

export interface GeneralLedgerRow {
  posting_date: string;
  posting_group_id: string;
  source_type: string;
  source_id: string;
  reversal_of_posting_group_id?: string;
  correction_reason?: string;
  ledger_entry_id: string;
  account_id: string;
  account_code: string;
  account_name: string;
  account_type: string;
  currency_code: string;
  debit: string;
  credit: string;
  net: string;
}

export interface GeneralLedgerResponse {
  data: GeneralLedgerRow[];
  pagination: {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export interface ProjectStatement {
  project: {
    id: string;
    name: string;
    crop_cycle?: CropCycle;
    party?: Party;
  };
  totals: {
    revenue: string;
    shared_costs: string;
    hari_only_costs: string;
  };
  settlement?: {
    pool_revenue: string;
    shared_costs: string;
    pool_profit: string;
    kamdari_amount: string;
    landlord_share: string;
    hari_share: string;
    hari_only_deductions: string;
    posting_date: string;
  };
}

// Form Payloads
export interface CreateUserPayload {
  name: string;
  email: string;
  role: UserRole;
}

export interface CreatePartyPayload {
  name: string;
  party_types: PartyType[];
}

export interface CreateLandParcelPayload {
  name: string;
  total_acres: number | string;
  notes?: string;
}

export interface CreateLandDocumentPayload {
  file_path: string;
  description?: string;
}

export interface CreateCropCyclePayload {
  name: string;
  crop_type?: string;
  start_date: string;
  end_date?: string;
}

export interface CreateLandAllocationPayload {
  crop_cycle_id: string;
  land_parcel_id: string;
  party_id: string;
  allocated_acres: number | string;
}

export interface CreateProjectPayload {
  name: string;
  party_id: string;
  crop_cycle_id: string;
  land_allocation_id?: string;
  status?: ProjectStatus;
}

export interface CreateProjectFromAllocationPayload {
  land_allocation_id: string;
  name: string;
}

export interface UpdateProjectRulePayload {
  profit_split_landlord_pct: number | string;
  profit_split_hari_pct: number | string;
  kamdari_pct: number | string;
  kamdar_party_id?: string;
  kamdari_order: KamdariOrder;
  pool_definition: PoolDefinition;
}

export interface CreateOperationalTransactionPayload {
  project_id?: string;
  crop_cycle_id?: string;
  type: TransactionType;
  transaction_date: string;
  amount: number | string;
  classification: TransactionClassification;
}

export interface UpdateOperationalTransactionPayload extends Partial<CreateOperationalTransactionPayload> {}

// Inventory
export interface InvUom {
  id: string;
  tenant_id: string;
  code: string;
  name: string;
  created_at: string;
  updated_at: string;
}

export interface InvItemCategory {
  id: string;
  tenant_id: string;
  name: string;
  created_at: string;
  updated_at: string;
}

export interface InvItem {
  id: string;
  tenant_id: string;
  name: string;
  sku?: string;
  category_id?: string;
  uom_id: string;
  valuation_method: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  category?: InvItemCategory;
  uom?: InvUom;
}

export interface InvStore {
  id: string;
  tenant_id: string;
  name: string;
  type: 'MAIN' | 'FIELD' | 'OTHER';
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface InvGrnLine {
  id: string;
  grn_id: string;
  item_id: string;
  qty: string;
  unit_cost: string;
  line_total: string;
  item?: InvItem;
}

export interface InvGrn {
  id: string;
  tenant_id: string;
  doc_no: string;
  supplier_party_id?: string;
  store_id: string;
  doc_date: string;
  status: 'DRAFT' | 'POSTED' | 'REVERSED';
  posting_date?: string;
  posting_group_id?: string;
  created_at: string;
  updated_at: string;
  store?: InvStore;
  supplier?: Party;
  lines?: InvGrnLine[];
  posting_group?: PostingGroup;
}

export interface InvIssueLine {
  id: string;
  issue_id: string;
  item_id: string;
  qty: string;
  unit_cost_snapshot?: string;
  line_total?: string;
  item?: InvItem;
}

export interface InvIssue {
  id: string;
  tenant_id: string;
  doc_no: string;
  store_id: string;
  crop_cycle_id: string;
  project_id: string;
  activity_id?: string;
  doc_date: string;
  status: 'DRAFT' | 'POSTED' | 'REVERSED';
  posting_date?: string;
  posting_group_id?: string;
  created_at: string;
  updated_at: string;
  store?: InvStore;
  crop_cycle?: CropCycle;
  project?: Project;
  lines?: InvIssueLine[];
  posting_group?: PostingGroup;
}

export interface InvStockBalance {
  id: string;
  tenant_id: string;
  store_id: string;
  item_id: string;
  qty_on_hand: string;
  value_on_hand: string;
  wac_cost: string;
  updated_at: string;
  store?: InvStore;
  item?: InvItem;
}

export interface InvStockMovement {
  id: string;
  tenant_id: string;
  posting_group_id: string;
  movement_type: string;
  store_id: string;
  item_id: string;
  qty_delta: string;
  value_delta: string;
  unit_cost_snapshot: string;
  occurred_at: string;
  source_type: string;
  source_id: string;
  created_at: string;
  store?: InvStore;
  item?: InvItem;
}

export interface CreateInvGrnPayload {
  doc_no: string;
  supplier_party_id?: string;
  store_id: string;
  doc_date: string;
  lines: { item_id: string; qty: number | string; unit_cost: number | string }[];
}

export interface UpdateInvGrnPayload {
  doc_no?: string;
  supplier_party_id?: string;
  store_id?: string;
  doc_date?: string;
  lines?: { item_id: string; qty: number | string; unit_cost: number | string }[];
}

export interface PostInvGrnRequest {
  posting_date: string;
  idempotency_key: string;
}

export interface ReverseInvGrnRequest {
  posting_date: string;
  reason: string;
}

export interface CreateInvIssuePayload {
  doc_no: string;
  store_id: string;
  crop_cycle_id: string;
  project_id: string;
  activity_id?: string;
  doc_date: string;
  lines: { item_id: string; qty: number | string }[];
}

export interface UpdateInvIssuePayload {
  doc_no?: string;
  store_id?: string;
  crop_cycle_id?: string;
  project_id?: string;
  activity_id?: string;
  doc_date?: string;
  lines?: { item_id: string; qty: number | string }[];
}

export interface PostInvIssueRequest {
  posting_date: string;
  idempotency_key: string;
}

export interface ReverseInvIssueRequest {
  posting_date: string;
  reason: string;
}

// Transfers
export interface InvTransferLine {
  id: string;
  transfer_id: string;
  item_id: string;
  qty: string;
  unit_cost_snapshot?: string;
  line_total?: string;
  item?: InvItem;
}

export interface InvTransfer {
  id: string;
  tenant_id: string;
  doc_no: string;
  from_store_id: string;
  to_store_id: string;
  doc_date: string;
  status: 'DRAFT' | 'POSTED' | 'REVERSED';
  posting_date?: string;
  posting_group_id?: string;
  created_at: string;
  updated_at: string;
  from_store?: InvStore;
  to_store?: InvStore;
  lines?: InvTransferLine[];
  posting_group?: PostingGroup;
}

export interface CreateInvTransferPayload {
  doc_no: string;
  from_store_id: string;
  to_store_id: string;
  doc_date: string;
  lines: { item_id: string; qty: number | string }[];
}

export interface UpdateInvTransferPayload {
  doc_no?: string;
  from_store_id?: string;
  to_store_id?: string;
  doc_date?: string;
  lines?: { item_id: string; qty: number | string }[];
}

export interface PostInvTransferRequest {
  posting_date: string;
  idempotency_key?: string;
}

export interface ReverseInvTransferRequest {
  posting_date: string;
  reason: string;
}

// Adjustments
export interface InvAdjustmentLine {
  id: string;
  adjustment_id: string;
  item_id: string;
  qty_delta: string;
  unit_cost_snapshot?: string;
  line_total?: string;
  item?: InvItem;
}

export type InvAdjustmentReason = 'LOSS' | 'DAMAGE' | 'COUNT_GAIN' | 'COUNT_LOSS' | 'OTHER';

export interface InvAdjustment {
  id: string;
  tenant_id: string;
  doc_no: string;
  store_id: string;
  reason: InvAdjustmentReason;
  notes?: string;
  doc_date: string;
  status: 'DRAFT' | 'POSTED' | 'REVERSED';
  posting_date?: string;
  posting_group_id?: string;
  created_at: string;
  updated_at: string;
  store?: InvStore;
  lines?: InvAdjustmentLine[];
  posting_group?: PostingGroup;
}

export interface CreateInvAdjustmentPayload {
  doc_no: string;
  store_id: string;
  reason: InvAdjustmentReason;
  notes?: string;
  doc_date: string;
  lines: { item_id: string; qty_delta: number | string }[];
}

export interface UpdateInvAdjustmentPayload {
  doc_no?: string;
  store_id?: string;
  reason?: InvAdjustmentReason;
  notes?: string;
  doc_date?: string;
  lines?: { item_id: string; qty_delta: number | string }[];
}

export interface PostInvAdjustmentRequest {
  posting_date: string;
  idempotency_key?: string;
}

export interface ReverseInvAdjustmentRequest {
  posting_date: string;
  reason: string;
}
