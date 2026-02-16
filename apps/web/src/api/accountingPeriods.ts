import { apiClient } from '@farm-erp/shared';
import type {
  AccountingPeriod,
  AccountingPeriodEvent,
  StoreAccountingPeriodPayload,
  CloseAccountingPeriodPayload,
  ReopenAccountingPeriodPayload,
} from '../types';

function listParams(params: { from?: string; to?: string }): string {
  const search = new URLSearchParams();
  if (params.from) search.set('from', params.from);
  if (params.to) search.set('to', params.to);
  const q = search.toString();
  return q ? `?${q}` : '';
}

export const accountingPeriodsApi = {
  list: (params: { from?: string; to?: string } = {}) =>
    apiClient.get<AccountingPeriod[]>(`/api/accounting-periods${listParams(params)}`),

  create: (payload: StoreAccountingPeriodPayload) =>
    apiClient.post<AccountingPeriod & { events?: AccountingPeriodEvent[] }>('/api/accounting-periods', payload),

  close: (id: string, payload?: CloseAccountingPeriodPayload) =>
    apiClient.post<AccountingPeriod>(`/api/accounting-periods/${id}/close`, payload ?? {}),

  reopen: (id: string, payload?: ReopenAccountingPeriodPayload) =>
    apiClient.post<AccountingPeriod>(`/api/accounting-periods/${id}/reopen`, payload ?? {}),

  events: (id: string) =>
    apiClient.get<AccountingPeriodEvent[]>(`/api/accounting-periods/${id}/events`),
};
