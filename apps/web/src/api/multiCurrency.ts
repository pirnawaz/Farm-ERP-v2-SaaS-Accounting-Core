import { apiClient } from '@farm-erp/shared';
import type {
  CreateExchangeRatePayload,
  ExchangeRateRow,
  FxRevaluationRun,
  PostingGroup,
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

export const exchangeRatesApi = {
  list: (opts?: {
    from_date?: string;
    to_date?: string;
    base_currency_code?: string;
    quote_currency_code?: string;
  }) =>
    apiClient.get<ExchangeRateRow[]>(
      `/api/exchange-rates${buildQuery({
        from_date: opts?.from_date,
        to_date: opts?.to_date,
        base_currency_code: opts?.base_currency_code,
        quote_currency_code: opts?.quote_currency_code,
      })}`
    ),

  create: (payload: CreateExchangeRatePayload) =>
    apiClient.post<ExchangeRateRow>('/api/exchange-rates', payload),
};

export const fxRevaluationApi = {
  list: (opts?: { status?: string }) =>
    apiClient.get<FxRevaluationRun[]>(
      `/api/fx-revaluation-runs${buildQuery({ status: opts?.status })}`
    ),

  get: (id: string) => apiClient.get<FxRevaluationRun>(`/api/fx-revaluation-runs/${id}`),

  createDraft: (as_of_date: string) =>
    apiClient.post<FxRevaluationRun>('/api/fx-revaluation-runs', { as_of_date }),

  refresh: (id: string) => apiClient.post<FxRevaluationRun>(`/api/fx-revaluation-runs/${id}/refresh`, {}),

  post: (id: string, payload: { posting_date: string; idempotency_key?: string | null }) =>
    apiClient.post<PostingGroup>(`/api/fx-revaluation-runs/${id}/post`, payload),
};
