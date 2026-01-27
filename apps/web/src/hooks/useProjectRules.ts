import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { projectRulesApi } from '../api/projectRules';
import type { UpdateProjectRulePayload } from '../types';

export function useProjectRule(projectId: string) {
  return useQuery({
    queryKey: ['projects', projectId, 'rules'],
    queryFn: () => projectRulesApi.get(projectId),
    enabled: !!projectId,
    staleTime: 5 * 60 * 1000, // 5 minutes - rules don't change frequently
    gcTime: 15 * 60 * 1000,
  });
}

export function useUpdateProjectRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ projectId, payload }: { projectId: string; payload: UpdateProjectRulePayload }) =>
      projectRulesApi.update(projectId, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['projects', variables.projectId, 'rules'] });
      queryClient.invalidateQueries({ queryKey: ['projects', variables.projectId] });
    },
  });
}
