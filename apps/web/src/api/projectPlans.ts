import { apiClient } from '@farm-erp/shared';
import type { CreateProjectPlanPayload, ProjectPlanApi } from '../types';

const BASE = '/api/plans/project';

export interface ProjectPlanListFilters {
  project_id?: string;
  crop_cycle_id?: string;
  status?: string;
}

function searchParams(f: ProjectPlanListFilters | undefined): string {
  const p = new URLSearchParams();
  if (f?.project_id) p.append('project_id', f.project_id);
  if (f?.crop_cycle_id) p.append('crop_cycle_id', f.crop_cycle_id);
  if (f?.status) p.append('status', f.status);
  const s = p.toString();
  return s ? `?${s}` : '';
}

export const projectPlansApi = {
  list: (f?: ProjectPlanListFilters) =>
    apiClient.get<ProjectPlanApi[]>(`${BASE}${searchParams(f || {})}`),
  create: (payload: CreateProjectPlanPayload) => apiClient.post<ProjectPlanApi>(BASE, payload),
};
