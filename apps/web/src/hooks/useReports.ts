import { useQuery } from '@tanstack/react-query';
import { reportsApi } from '../api/reports';
import type { GeneralLedgerResponse, TrialBalanceRow, ProfitLossResponse, BalanceSheetResponse } from '../types';

export function useTrialBalance(params: { from: string; to: string }) {
  return useQuery<TrialBalanceRow[], Error>({
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
