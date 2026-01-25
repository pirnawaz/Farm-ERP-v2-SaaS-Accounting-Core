import { apiClient } from '@farm-erp/shared';
import type { TrialBalanceRow, GeneralLedgerResponse, ProjectStatement } from '../types';

export const reportsApi = {
  trialBalance: (params: { from: string; to: string }) => {
    const query = new URLSearchParams();
    query.append('from', params.from);
    query.append('to', params.to);
    return apiClient.get<TrialBalanceRow[]>(`/api/reports/trial-balance?${query.toString()}`);
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
};
