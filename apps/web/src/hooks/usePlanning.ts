import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { projectPlansApi, type ProjectPlanListFilters } from '../api/projectPlans';
import { getApiErrorMessage } from '../utils/api';
import type { CreateProjectPlanPayload, ProjectPlanApi } from '../types';

export function useProjectPlansList(filters: ProjectPlanListFilters, options?: { enabled?: boolean }) {
  return useQuery<ProjectPlanApi[], Error>({
    queryKey: ['plans', 'project', filters],
    queryFn: () => projectPlansApi.list(filters),
    enabled: options?.enabled !== false,
    staleTime: 30 * 1000,
    gcTime: 10 * 60 * 1000,
  });
}

export function useCreateProjectPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateProjectPlanPayload) => projectPlansApi.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['plans', 'project'] });
      qc.invalidateQueries({ queryKey: ['reports', 'project-forecast'] });
      qc.invalidateQueries({ queryKey: ['reports', 'project-projected-profit'] });
      toast.success('Plan saved. Forecast updates below.');
    },
    onError: (e: unknown) => {
      toast.error(getApiErrorMessage(e, 'Could not save plan'));
    },
  });
}
