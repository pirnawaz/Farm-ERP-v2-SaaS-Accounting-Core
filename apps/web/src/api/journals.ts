import { apiClient } from '@farm-erp/shared';
import type {
  JournalEntry,
  StoreJournalPayload,
  UpdateJournalPayload,
  ReverseJournalPayload,
} from '../types';

function listParams(params: {
  from?: string;
  to?: string;
  status?: string;
  q?: string;
  limit?: number;
  offset?: number;
}): string {
  const search = new URLSearchParams();
  if (params.from) search.set('from', params.from);
  if (params.to) search.set('to', params.to);
  if (params.status) search.set('status', params.status);
  if (params.q) search.set('q', params.q);
  if (params.limit != null) search.set('limit', String(params.limit));
  if (params.offset != null) search.set('offset', String(params.offset));
  const q = search.toString();
  return q ? `?${q}` : '';
}

export const journalsApi = {
  create: (payload: StoreJournalPayload) =>
    apiClient.post<JournalEntry>('/api/journals', payload),

  list: (params: { from?: string; to?: string; status?: string; q?: string; limit?: number; offset?: number } = {}) =>
    apiClient.get<JournalEntry[]>(`/api/journals${listParams(params)}`),

  get: (id: string) =>
    apiClient.get<JournalEntry & { total_debits?: number; total_credits?: number }>(`/api/journals/${id}`),

  update: (id: string, payload: UpdateJournalPayload) =>
    apiClient.put<JournalEntry>(`/api/journals/${id}`, payload),

  post: (id: string) =>
    apiClient.post<{ journal: JournalEntry; posting_group: unknown }>(`/api/journals/${id}/post`, {}),

  reverse: (id: string, payload?: ReverseJournalPayload) =>
    apiClient.post<{ journal: JournalEntry; reversal_posting_group: unknown }>(
      `/api/journals/${id}/reverse`,
      payload ?? {}
    ),
};
