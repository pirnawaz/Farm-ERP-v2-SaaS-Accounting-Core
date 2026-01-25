import { useQuery } from '@tanstack/react-query';
import { reportsApi } from '../api/reports';

export function useTrialBalance(params: { from: string; to: string }) {
  return useQuery({
    queryKey: ['reports', 'trial-balance', params],
    queryFn: () => reportsApi.trialBalance(params),
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
  return useQuery({
    queryKey: ['reports', 'general-ledger', params],
    queryFn: () => reportsApi.generalLedger(params),
  });
}

export function useProjectStatement(params: { project_id: string; up_to_date?: string }) {
  return useQuery({
    queryKey: ['reports', 'project-statement', params],
    queryFn: () => reportsApi.projectStatement(params),
    enabled: !!params.project_id,
  });
}
