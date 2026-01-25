import { apiClient } from '@farm-erp/shared';
import type {
  LabWorker,
  LabWorkLog,
  PayablesOutstandingRow,
  CreateLabWorkerPayload,
  UpdateLabWorkerPayload,
  CreateLabWorkLogPayload,
  UpdateLabWorkLogPayload,
  PostLabWorkLogRequest,
  ReverseLabWorkLogRequest,
  PostingGroup,
} from '../types';

const BASE = '/api/v1/labour';

export interface WorkerFilters {
  is_active?: boolean;
  worker_type?: string;
  q?: string;
}

export interface WorkLogFilters {
  status?: string;
  worker_id?: string;
  crop_cycle_id?: string;
  project_id?: string;
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

export const labourApi = {
  workers: {
    list: (f?: WorkerFilters) => apiClient.get<LabWorker[]>(`${BASE}/workers${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<LabWorker>(`${BASE}/workers/${id}`),
    create: (payload: CreateLabWorkerPayload) => apiClient.post<LabWorker>(`${BASE}/workers`, payload),
    update: (id: string, payload: UpdateLabWorkerPayload) => apiClient.patch<LabWorker>(`${BASE}/workers/${id}`, payload),
  },
  workLogs: {
    list: (f?: WorkLogFilters) => apiClient.get<LabWorkLog[]>(`${BASE}/work-logs${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<LabWorkLog>(`${BASE}/work-logs/${id}`),
    create: (payload: CreateLabWorkLogPayload) => apiClient.post<LabWorkLog>(`${BASE}/work-logs`, payload),
    update: (id: string, payload: UpdateLabWorkLogPayload) => apiClient.patch<LabWorkLog>(`${BASE}/work-logs/${id}`, payload),
    post: (id: string, payload: PostLabWorkLogRequest) => apiClient.post<PostingGroup>(`${BASE}/work-logs/${id}/post`, payload),
    reverse: (id: string, payload: ReverseLabWorkLogRequest) => apiClient.post<PostingGroup>(`${BASE}/work-logs/${id}/reverse`, payload),
  },
  payables: {
    outstanding: () => apiClient.get<PayablesOutstandingRow[]>(`${BASE}/payables/outstanding`),
  },
};
