import { apiClient } from '@farm-erp/shared';

const BASE = '/api/v1/farm-activity';

export type FarmActivityKind = 'field_job' | 'harvest' | 'sale';

export interface FarmActivityTimelineItem {
  kind: FarmActivityKind;
  id: string;
  activity_date: string;
  title: string;
  reference: string | null;
  summary: string;
  status: string;
}

export interface FarmActivityTimelineResponse {
  items: FarmActivityTimelineItem[];
  generated_at: string;
}

export interface FarmActivityTimelineParams {
  from?: string;
  to?: string;
  limit?: number;
}

export const farmActivityApi = {
  getTimeline: (params?: FarmActivityTimelineParams) => {
    const q = new URLSearchParams();
    if (params?.from) {
      q.set('from', params.from);
    }
    if (params?.to) {
      q.set('to', params.to);
    }
    if (params?.limit != null) {
      q.set('limit', String(params.limit));
    }
    const s = q.toString();

    return apiClient.get<FarmActivityTimelineResponse>(`${BASE}/timeline${s ? `?${s}` : ''}`);
  },
};
