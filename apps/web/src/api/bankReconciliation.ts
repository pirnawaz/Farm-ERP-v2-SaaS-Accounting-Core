import { apiClient } from '@farm-erp/shared';
import type {
  BankReconciliationListItem,
  BankReconciliationReport,
  CreateBankReconciliationPayload,
  ClearBankEntriesPayload,
  UnclearBankEntriesPayload,
  AddStatementLinePayload,
} from '../types';

function listParams(accountCode?: string, limit = 50): string {
  const params = new URLSearchParams();
  if (accountCode) params.append('account_code', accountCode);
  params.append('limit', String(Math.min(Math.max(limit, 1), 100)));
  const q = params.toString();
  return q ? `?${q}` : '';
}

export const bankReconciliationApi = {
  create: (payload: CreateBankReconciliationPayload) =>
    apiClient.post<BankReconciliationListItem & { account: { id: string; code: string; name: string } }>(
      '/api/bank-reconciliations',
      payload
    ),

  list: (params: { account_code?: string; limit?: number } = {}) =>
    apiClient.get<BankReconciliationListItem[]>(
      `/api/bank-reconciliations${listParams(params.account_code, params.limit ?? 50)}`
    ),

  get: (id: string) =>
    apiClient.get<BankReconciliationReport>(`/api/bank-reconciliations/${id}`),

  clear: (id: string, payload: ClearBankEntriesPayload) =>
    apiClient.post<{ cleared: string[] }>(`/api/bank-reconciliations/${id}/clear`, payload),

  unclear: (id: string, payload: UnclearBankEntriesPayload) =>
    apiClient.post<{ voided: number }>(`/api/bank-reconciliations/${id}/unclear`, payload),

  finalize: (id: string) =>
    apiClient.post<BankReconciliationListItem & { account: { id: string; code: string; name: string } }>(
      `/api/bank-reconciliations/${id}/finalize`,
      {}
    ),

  addStatementLine: (reconciliationId: string, payload: AddStatementLinePayload) =>
    apiClient.post<{ id: string; line_date: string; amount: number; description?: string; reference?: string; status: string }>(
      `/api/bank-reconciliations/${reconciliationId}/statement-lines`,
      payload
    ),

  voidStatementLine: (reconciliationId: string, lineId: string, body?: { reason?: string }) =>
    apiClient.post<{ id: string; status: string }>(
      `/api/bank-reconciliations/${reconciliationId}/statement-lines/${lineId}/void`,
      body ?? {}
    ),

  matchStatementLine: (reconciliationId: string, lineId: string, body: { ledger_entry_id: string }) =>
    apiClient.post<{ id: string; ledger_entry_id: string; status: string }>(
      `/api/bank-reconciliations/${reconciliationId}/statement-lines/${lineId}/match`,
      body
    ),

  unmatchStatementLine: (reconciliationId: string, lineId: string, body?: { reason?: string }) =>
    apiClient.post<{ voided: number }>(
      `/api/bank-reconciliations/${reconciliationId}/statement-lines/${lineId}/unmatch`,
      body ?? {}
    ),
};
