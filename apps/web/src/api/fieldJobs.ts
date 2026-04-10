import { apiClient } from '@farm-erp/shared';
import type {
  FieldJob,
  CreateFieldJobPayload,
  UpdateFieldJobPayload,
  AddFieldJobInputPayload,
  UpdateFieldJobInputPayload,
  AddFieldJobLabourPayload,
  UpdateFieldJobLabourPayload,
  AddFieldJobMachinePayload,
  UpdateFieldJobMachinePayload,
  PostFieldJobRequest,
  ReverseFieldJobRequest,
  FieldJobInputLine,
  FieldJobLabourLine,
  FieldJobMachineLine,
  PostingGroup,
} from '../types';

const BASE = '/api/v1/crop-ops/field-jobs';

export interface FieldJobFilters {
  status?: string;
  crop_cycle_id?: string;
  project_id?: string;
  from?: string;
  to?: string;
}

function searchParams(obj: FieldJobFilters | undefined): string {
  const r = (obj || {}) as Record<string, string | undefined>;
  const p = new URLSearchParams();
  Object.entries(r).forEach(([k, v]) => {
    if (v) p.append(k, v);
  });
  const s = p.toString();
  return s ? `?${s}` : '';
}

export const fieldJobsApi = {
  list: (f?: FieldJobFilters) =>
    apiClient.get<FieldJob[]>(`${BASE}${searchParams(f || {})}`),
  get: (id: string) => apiClient.get<FieldJob>(`${BASE}/${id}`),
  create: (payload: CreateFieldJobPayload) => apiClient.post<FieldJob>(`${BASE}`, payload),
  update: (id: string, payload: UpdateFieldJobPayload) =>
    apiClient.put<FieldJob>(`${BASE}/${id}`, payload),
  addInput: (id: string, payload: AddFieldJobInputPayload) =>
    apiClient.post<FieldJobInputLine>(`${BASE}/${id}/inputs`, payload),
  updateInput: (id: string, lineId: string, payload: UpdateFieldJobInputPayload) =>
    apiClient.put<FieldJobInputLine>(`${BASE}/${id}/inputs/${lineId}`, payload),
  deleteInput: (id: string, lineId: string) =>
    apiClient.delete<{ ok?: boolean }>(`${BASE}/${id}/inputs/${lineId}`),
  addLabour: (id: string, payload: AddFieldJobLabourPayload) =>
    apiClient.post<FieldJobLabourLine>(`${BASE}/${id}/labour`, payload),
  updateLabour: (id: string, lineId: string, payload: UpdateFieldJobLabourPayload) =>
    apiClient.put<FieldJobLabourLine>(`${BASE}/${id}/labour/${lineId}`, payload),
  deleteLabour: (id: string, lineId: string) =>
    apiClient.delete<{ ok?: boolean }>(`${BASE}/${id}/labour/${lineId}`),
  addMachine: (id: string, payload: AddFieldJobMachinePayload) =>
    apiClient.post<FieldJobMachineLine>(`${BASE}/${id}/machines`, payload),
  updateMachine: (id: string, lineId: string, payload: UpdateFieldJobMachinePayload) =>
    apiClient.put<FieldJobMachineLine>(`${BASE}/${id}/machines/${lineId}`, payload),
  deleteMachine: (id: string, lineId: string) =>
    apiClient.delete<{ ok?: boolean }>(`${BASE}/${id}/machines/${lineId}`),
  post: (id: string, payload: PostFieldJobRequest) =>
    apiClient.post<PostingGroup>(`${BASE}/${id}/post`, payload),
  reverse: (id: string, payload: ReverseFieldJobRequest) =>
    apiClient.post<PostingGroup>(`${BASE}/${id}/reverse`, payload),
};
