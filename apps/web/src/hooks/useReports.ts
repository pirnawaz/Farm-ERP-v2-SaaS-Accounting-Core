import { useQuery } from '@tanstack/react-query';
import { reportsApi } from '../api/reports';
import type { GeneralLedgerResponse, TrialBalanceRow } from '../types';

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
