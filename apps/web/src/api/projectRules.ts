import { apiClient } from '@farm-erp/shared';
import type { ProjectRule, UpdateProjectRulePayload } from '../types';

// Extend apiClient with put method
const apiClientWithPut = apiClient as typeof apiClient & {
  put: <T>(endpoint: string, data: unknown) => Promise<T>;
};

export const projectRulesApi = {
  get: (projectId: string) => apiClient.get<ProjectRule>(`/api/projects/${projectId}/rules`),
  update: (projectId: string, payload: UpdateProjectRulePayload) => 
    apiClientWithPut.put<ProjectRule>(`/api/projects/${projectId}/rules`, payload),
};
