import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { reportsApi } from '../api/reports';
import { getApiErrorMessage } from '../utils/api';
import type {
  GeneralLedgerResponse,
  TrialBalanceRow,
  ProfitLossResponse,
  BalanceSheetResponse,
  CropProfitabilityResponse,
  CropProfitabilityGroupBy,
  CropProfitabilityTrendResponse,
  CropProfitabilityTrendGroupBy,
  ProductionUnitSummaryResponse,
  LivestockUnitStatusResponse,
  ProductionUnitsProfitabilityResponse,
  ProductionUnitCategoryFilter,
} from '../types';

export function useTrialBalance(params: { as_of: string; project_id?: string; crop_cycle_id?: string; currency_code?: string }) {
  return useQuery<{ as_of: string; rows: TrialBalanceRow[]; totals: { total_debit: string; total_credit: string }; balanced: boolean }, Error>({
    queryKey: ['reports', 'trial-balance', params],
    queryFn: () => reportsApi.trialBalance(params),
    staleTime: 2 * 60 * 1000, // 2 minutes - date-filtered reports
    gcTime: 10 * 60 * 1000,
  });
}

export function useGeneralLedger(params: { 
  from: string; 
  to: string; 
  account_id?: string; 
  project_id?: string; 
  page?: number; 
  per_page?: number 
}) {
  return useQuery<GeneralLedgerResponse, Error>({
    queryKey: ['reports', 'general-ledger', params],
    queryFn: () => reportsApi.generalLedger(params),
    staleTime: 2 * 60 * 1000, // 2 minutes - reports are date-filtered, stable for a period
    gcTime: 10 * 60 * 1000,
  });
}

export function useProjectStatement(params: { project_id: string; up_to_date?: string }) {
  return useQuery({
    queryKey: ['reports', 'project-statement', params],
    queryFn: () => reportsApi.projectStatement(params),
    enabled: !!params.project_id,
    staleTime: 2 * 60 * 1000, // 2 minutes
    gcTime: 10 * 60 * 1000,
  });
}

export function useProfitLoss(params: { from: string; to: string; compare_from?: string; compare_to?: string }) {
  return useQuery<ProfitLossResponse, Error>({
    queryKey: ['reports', 'profit-loss', params],
    queryFn: () => reportsApi.profitLoss(params),
    enabled: !!params.from && !!params.to,
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}

export function useBalanceSheet(params: { as_of: string; compare_as_of?: string }) {
  return useQuery<BalanceSheetResponse, Error>({
    queryKey: ['reports', 'balance-sheet', params],
    queryFn: () => reportsApi.balanceSheet(params),
    enabled: !!params.as_of,
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}

export function useCropProfitability(
  params: {
    from: string;
    to: string;
    group_by?: CropProfitabilityGroupBy;
    include_unassigned?: boolean;
    production_unit_id?: string;
  },
  options?: { enabled?: boolean }
) {
  const enabled = options?.enabled !== false && !!params.from && !!params.to;
  const query = useQuery<CropProfitabilityResponse, Error>({
    queryKey: ['reports', 'crop-profitability', params],
    queryFn: () => reportsApi.getCropProfitability(params),
    enabled,
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
  useEffect(() => {
    if (query.error) {
      toast.error(getApiErrorMessage(query.error, 'Failed to load crop profitability report'));
    }
  }, [query.error]);
  return query;
}

export function useCropProfitabilityTrend(
  params: {
    from: string;
    to: string;
    group_by?: CropProfitabilityTrendGroupBy;
    include_unassigned?: boolean;
  },
  options?: { enabled?: boolean }
) {
  const enabled = options?.enabled !== false && !!params.from && !!params.to;
  const query = useQuery<CropProfitabilityTrendResponse, Error>({
    queryKey: ['reports', 'crop-profitability-trend', params],
    queryFn: () => reportsApi.getCropProfitabilityTrend(params),
    enabled,
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
  useEffect(() => {
    if (query.error) {
      toast.error(getApiErrorMessage(query.error, 'Failed to load crop profitability trend'));
    }
  }, [query.error]);
  return query;
}

export function useProductionUnitSummary(
  params: { production_unit_id: string; from: string; to: string },
  options?: { enabled?: boolean }
) {
  const enabled = options?.enabled !== false && !!params.production_unit_id && !!params.from && !!params.to;
  return useQuery<ProductionUnitSummaryResponse, Error>({
    queryKey: ['reports', 'production-unit-summary', params],
    queryFn: () => reportsApi.getProductionUnitSummary(params),
    enabled,
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}

export function useLivestockUnitStatus(
  params: { production_unit_id: string; as_of: string },
  options?: { enabled?: boolean }
) {
  const enabled = options?.enabled !== false && !!params.production_unit_id && !!params.as_of;
  return useQuery<LivestockUnitStatusResponse, Error>({
    queryKey: ['reports', 'livestock-unit-status', params],
    queryFn: () => reportsApi.getLivestockUnitStatus(params),
    enabled,
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}

export function useProductionUnitsProfitability(
  params: { from: string; to: string; category?: ProductionUnitCategoryFilter },
  options?: { enabled?: boolean }
) {
  const enabled = options?.enabled !== false && !!params.from && !!params.to;
  return useQuery<ProductionUnitsProfitabilityResponse, Error>({
    queryKey: ['reports', 'production-units-profitability', params],
    queryFn: () => reportsApi.productionUnitsProfitability(params),
    enabled,
    staleTime: 2 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}
