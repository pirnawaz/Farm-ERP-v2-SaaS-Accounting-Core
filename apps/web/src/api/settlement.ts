import { apiClient } from '@farm-erp/shared';
import type { SettlementPreview, PostSettlementRequest, SettlementPostResult, SettlementOffsetPreview } from '../types';

export const settlementApi = {
  preview: (projectId: string, upToDate?: string) => {
    const payload = upToDate ? { up_to_date: upToDate } : {};
    return apiClient.post<SettlementPreview>(`/api/projects/${projectId}/settlement/preview`, payload);
  },
  offsetPreview: (projectId: string, postingDate: string) => {
    const q = new URLSearchParams({ posting_date: postingDate }).toString();
    return apiClient.get<SettlementOffsetPreview>(`/api/projects/${projectId}/settlement/offset-preview?${q}`);
  },
  post: (projectId: string, payload: PostSettlementRequest) => 
    apiClient.post<SettlementPostResult>(`/api/projects/${projectId}/settlement/post`, payload),
};
