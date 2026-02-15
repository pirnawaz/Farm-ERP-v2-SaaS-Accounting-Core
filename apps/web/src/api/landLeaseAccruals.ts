import { apiClient } from '@farm-erp/shared';
import type {
  LandLeaseAccrual,
  CreateLandLeaseAccrualPayload,
  UpdateLandLeaseAccrualPayload,
  PostLandLeaseAccrualPayload,
  PostLandLeaseAccrualResponse,
  ReverseLandLeaseAccrualPayload,
  ReverseLandLeaseAccrualResponse,
} from '@farm-erp/shared';

export interface LandLeaseAccrualsListParams {
  lease_id?: string;
  project_id?: string;
  per_page?: number;
}

function buildQuery(params?: LandLeaseAccrualsListParams): string {
  if (!params) return '';
  const search = new URLSearchParams();
  if (params.lease_id) search.set('lease_id', params.lease_id);
  if (params.project_id) search.set('project_id', params.project_id);
  if (params.per_page != null) search.set('per_page', String(params.per_page));
  const q = search.toString();
  return q ? `?${q}` : '';
}

export const landLeaseAccrualsApi = {
  list: (params?: LandLeaseAccrualsListParams) =>
    apiClient.get<{
      data: LandLeaseAccrual[];
      current_page: number;
      last_page: number;
      per_page: number;
      total: number;
    }>(`/api/land-lease-accruals${buildQuery(params)}`),

  get: (id: string) =>
    apiClient.get<LandLeaseAccrual>(`/api/land-lease-accruals/${id}`),

  create: (payload: CreateLandLeaseAccrualPayload) =>
    apiClient.post<LandLeaseAccrual>('/api/land-lease-accruals', payload),

  update: (id: string, payload: UpdateLandLeaseAccrualPayload) =>
    apiClient.put<LandLeaseAccrual>(`/api/land-lease-accruals/${id}`, payload),

  delete: (id: string) =>
    apiClient.delete(`/api/land-lease-accruals/${id}`),

  post: (id: string, payload: PostLandLeaseAccrualPayload) =>
    apiClient.post<PostLandLeaseAccrualResponse>(
      `/api/land-lease-accruals/${id}/post`,
      payload
    ),

  reverse: (id: string, payload: ReverseLandLeaseAccrualPayload) =>
    apiClient.post<ReverseLandLeaseAccrualResponse>(
      `/api/land-lease-accruals/${id}/reverse`,
      payload
    ),
};
