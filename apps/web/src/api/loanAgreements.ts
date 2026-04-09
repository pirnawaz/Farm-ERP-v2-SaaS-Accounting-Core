import { apiClient } from '@farm-erp/shared';
import type {
  LoanAgreementDetail,
  LoanAgreementListItem,
  LoanAgreementStatement,
} from '@farm-erp/shared';

function buildQuery(params: Record<string, string | undefined>): string {
  const searchParams = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') {
      searchParams.append(key, value);
    }
  });
  const query = searchParams.toString();
  return query ? `?${query}` : '';
}

export const loanAgreementsApi = {
  list: () =>
    apiClient.get<{ data: LoanAgreementListItem[] }>('/api/loan-agreements'),

  get: (id: string) =>
    apiClient.get<LoanAgreementDetail>(`/api/loan-agreements/${id}`),

  getStatement: (id: string, opts?: { from?: string; to?: string }) =>
    apiClient.get<LoanAgreementStatement>(
      `/api/loan-agreements/${id}/statement${buildQuery({
        from: opts?.from,
        to: opts?.to,
      })}`
    ),
};
