import { apiClient } from '@farm-erp/shared';
import type {
  CropActivityType,
  CropActivity,
  CreateActivityTypePayload,
  UpdateActivityTypePayload,
  CreateCropActivityPayload,
  UpdateCropActivityPayload,
  PostCropActivityRequest,
  ReverseCropActivityRequest,
  PostingGroup,
} from '../types';

const BASE = '/api/v1/crop-ops';

export interface ActivityTypeFilters {
  is_active?: boolean;
}

export interface ActivityFilters {
  status?: string;
  crop_cycle_id?: string;
  project_id?: string;
  activity_type_id?: string;
  land_parcel_id?: string;
  from?: string;
  to?: string;
}

function searchParams(obj: Record<string, string | undefined> | object): string {
  const r = (obj || {}) as Record<string, string | undefined>;
  const p = new URLSearchParams();
  Object.entries(r).forEach(([k, v]) => { if (v) p.append(k, v); });
  const s = p.toString();
  return s ? `?${s}` : '';
}

export const cropOpsApi = {
  activityTypes: {
    list: (f?: ActivityTypeFilters) => apiClient.get<CropActivityType[]>(`${BASE}/activity-types${searchParams(f || {})}`),
    create: (payload: CreateActivityTypePayload) => apiClient.post<CropActivityType>(`${BASE}/activity-types`, payload),
    update: (id: string, payload: UpdateActivityTypePayload) => apiClient.patch<CropActivityType>(`${BASE}/activity-types/${id}`, payload),
  },
  activities: {
    list: (f?: ActivityFilters) => apiClient.get<CropActivity[]>(`${BASE}/activities${searchParams(f || {})}`),
    timeline: (f?: ActivityFilters) => apiClient.get<CropActivity[]>(`${BASE}/activities/timeline${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<CropActivity>(`${BASE}/activities/${id}`),
    create: (payload: CreateCropActivityPayload) => apiClient.post<CropActivity>(`${BASE}/activities`, payload),
    update: (id: string, payload: UpdateCropActivityPayload) => apiClient.patch<CropActivity>(`${BASE}/activities/${id}`, payload),
    post: (id: string, payload: PostCropActivityRequest) => apiClient.post<PostingGroup>(`${BASE}/activities/${id}/post`, payload),
    reverse: (id: string, payload: ReverseCropActivityRequest) => apiClient.post<PostingGroup>(`${BASE}/activities/${id}/reverse`, payload),
  },
};
