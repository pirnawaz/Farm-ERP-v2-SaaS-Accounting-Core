import { apiClient } from '@farm-erp/shared';
import type { LandlordStatementResponse } from '@farm-erp/shared';
import type { TrialBalanceRow, GeneralLedgerResponse, ProjectStatement, PartyLedgerResponse, PartySummaryResponse, RoleAgeingResponse, ProfitLossResponse, BalanceSheetResponse, CropProfitabilityResponse, CropProfitabilityGroupBy, CropProfitabilityTrendResponse, CropProfitabilityTrendGroupBy, ProductionUnitSummaryResponse, LivestockUnitStatusResponse } from '../types';

export const reportsApi = {
  trialBalance: (params: { as_of: string; project_id?: string; crop_cycle_id?: string; currency_code?: string }) => {
    const query = new URLSearchParams();
    query.append('as_of', params.as_of);
    if (params.project_id) query.append('project_id', params.project_id);
    if (params.crop_cycle_id) query.append('crop_cycle_id', params.crop_cycle_id);
    if (params.currency_code) query.append('currency_code', params.currency_code);
    return apiClient.get<{ as_of: string; rows: TrialBalanceRow[]; totals: { total_debit: string; total_credit: string }; balanced: boolean }>(
      `/api/reports/trial-balance?${query.toString()}`
    );
  },
  generalLedger: (params: { 
    from: string; 
    to: string; 
    account_id?: string; 
    project_id?: string; 
    page?: number; 
    per_page?: number 
  }) => {
    const query = new URLSearchParams();
    query.append('from', params.from);
    query.append('to', params.to);
    if (params.account_id) query.append('account_id', params.account_id);
    if (params.project_id) query.append('project_id', params.project_id);
    if (params.page) query.append('page', params.page.toString());
    if (params.per_page) query.append('per_page', params.per_page.toString());
    return apiClient.get<GeneralLedgerResponse>(`/api/reports/general-ledger?${query.toString()}`);
  },
  projectStatement: (params: { project_id: string; up_to_date?: string }) => {
    const query = new URLSearchParams();
    query.append('project_id', params.project_id);
    if (params.up_to_date) query.append('up_to_date', params.up_to_date);
    return apiClient.get<ProjectStatement>(`/api/reports/project-statement?${query.toString()}`);
  },
  salesMargin: (params: { crop_cycle_id?: string; from?: string; to?: string; group_by?: string }) => {
    const query = new URLSearchParams();
    if (params.crop_cycle_id) query.append('crop_cycle_id', params.crop_cycle_id);
    if (params.from) query.append('from', params.from);
    if (params.to) query.append('to', params.to);
    if (params.group_by) query.append('group_by', params.group_by);
    return apiClient.get(`/api/reports/sales-margin?${query.toString()}`);
  },
  cashbook: (params: { from: string; to: string }) => {
    const query = new URLSearchParams();
    query.append('from', params.from);
    query.append('to', params.to);
    return apiClient.get(`/api/reports/cashbook?${query.toString()}`);
  },
  partyLedger: (params: {
    party_id: string;
    from: string;
    to: string;
    project_id?: string;
    crop_cycle_id?: string;
  }) => {
    const query = new URLSearchParams();
    query.append('party_id', params.party_id);
    query.append('from', params.from);
    query.append('to', params.to);
    if (params.project_id) query.append('project_id', params.project_id);
    if (params.crop_cycle_id) query.append('crop_cycle_id', params.crop_cycle_id);
    return apiClient.get<PartyLedgerResponse>(`/api/reports/party-ledger?${query.toString()}`);
  },
  partySummary: (params: {
    from: string;
    to: string;
    role?: string;
    project_id?: string;
    crop_cycle_id?: string;
  }) => {
    const query = new URLSearchParams();
    query.append('from', params.from);
    query.append('to', params.to);
    if (params.role) query.append('role', params.role);
    if (params.project_id) query.append('project_id', params.project_id);
    if (params.crop_cycle_id) query.append('crop_cycle_id', params.crop_cycle_id);
    return apiClient.get<PartySummaryResponse>(`/api/reports/party-summary?${query.toString()}`);
  },
  roleAgeing: (params: {
    as_of: string;
    project_id?: string;
    crop_cycle_id?: string;
  }) => {
    const query = new URLSearchParams();
    query.append('as_of', params.as_of);
    if (params.project_id) query.append('project_id', params.project_id);
    if (params.crop_cycle_id) query.append('crop_cycle_id', params.crop_cycle_id);
    return apiClient.get<RoleAgeingResponse>(`/api/reports/role-ageing?${query.toString()}`);
  },
  landlordStatement: (params: { party_id: string; date_from: string; date_to: string }) => {
    const query = new URLSearchParams();
    query.append('party_id', params.party_id);
    query.append('date_from', params.date_from);
    query.append('date_to', params.date_to);
    return apiClient.get<LandlordStatementResponse>(`/api/reports/landlord-statement?${query.toString()}`);
  },
  reconcileProject: (params: { project_id: string; from: string; to: string }) =>
    apiClient.reconcileProject(params),
  reconcileCropCycle: (params: { crop_cycle_id: string; from: string; to: string }) =>
    apiClient.reconcileCropCycle(params),
  reconcileSupplierAp: (params: { party_id: string; from: string; to: string }) =>
    apiClient.reconcileSupplierAp(params),

  profitLoss: (params: { from: string; to: string; compare_from?: string; compare_to?: string }) => {
    const query = new URLSearchParams();
    query.append('from', params.from);
    query.append('to', params.to);
    if (params.compare_from) query.append('compare_from', params.compare_from);
    if (params.compare_to) query.append('compare_to', params.compare_to);
    return apiClient.get<ProfitLossResponse>(`/api/reports/profit-loss?${query.toString()}`);
  },

  balanceSheet: (params: { as_of: string; compare_as_of?: string }) => {
    const query = new URLSearchParams();
    query.append('as_of', params.as_of);
    if (params.compare_as_of) query.append('compare_as_of', params.compare_as_of);
    return apiClient.get<BalanceSheetResponse>(`/api/reports/balance-sheet?${query.toString()}`);
  },

  getCropProfitability: (params: {
    from: string;
    to: string;
    group_by?: CropProfitabilityGroupBy;
    include_unassigned?: boolean;
    production_unit_id?: string;
  }) => {
    const query = new URLSearchParams();
    query.append('from', params.from);
    query.append('to', params.to);
    if (params.group_by) query.append('group_by', params.group_by);
    if (params.include_unassigned !== undefined) query.append('include_unassigned', params.include_unassigned ? '1' : '0');
    if (params.production_unit_id) query.append('production_unit_id', params.production_unit_id);
    return apiClient.get<CropProfitabilityResponse>(`/api/reports/crop-profitability?${query.toString()}`);
  },

  getCropProfitabilityTrend: (params: {
    from: string;
    to: string;
    group_by?: CropProfitabilityTrendGroupBy;
    include_unassigned?: boolean;
  }) => {
    const query = new URLSearchParams();
    query.append('from', params.from);
    query.append('to', params.to);
    if (params.group_by) query.append('group_by', params.group_by);
    if (params.include_unassigned !== undefined) query.append('include_unassigned', params.include_unassigned ? '1' : '0');
    return apiClient.get<CropProfitabilityTrendResponse>(`/api/reports/crop-profitability-trend?${query.toString()}`);
  },

  getProductionUnitSummary: (params: { production_unit_id: string; from: string; to: string }) => {
    const query = new URLSearchParams();
    query.append('production_unit_id', params.production_unit_id);
    query.append('from', params.from);
    query.append('to', params.to);
    return apiClient.get<ProductionUnitSummaryResponse>(`/api/reports/production-unit-summary?${query.toString()}`);
  },

  getLivestockUnitStatus: (params: { production_unit_id: string; as_of: string }) => {
    const query = new URLSearchParams();
    query.append('production_unit_id', params.production_unit_id);
    query.append('as_of', params.as_of);
    return apiClient.get<LivestockUnitStatusResponse>(`/api/reports/livestock-unit-status?${query.toString()}`);
  },
};
