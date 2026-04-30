import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { projectsApi } from '../api/projects';
import type { CreateProjectPayload, CreateProjectFromAllocationPayload, FieldCycleSetupPayload } from '../types';

export function useProjects(cropCycleId?: string) {
  return useQuery({
    queryKey: ['projects', cropCycleId],
    queryFn: () => projectsApi.list(cropCycleId),
    staleTime: 10 * 60 * 1000, // 10 minutes - reference data
    gcTime: 30 * 60 * 1000, // 30 minutes - keep in cache longer
  });
}

export function useProject(id: string) {
  return useQuery({
    queryKey: ['projects', id],
    queryFn: () => projectsApi.get(id),
    enabled: !!id,
    staleTime: 5 * 60 * 1000, // 5 minutes - individual items change less frequently
    gcTime: 30 * 60 * 1000,
  });
}

export function useCreateProject() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateProjectPayload) => projectsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
    },
  });
}

export function useCreateProjectFromAllocation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateProjectFromAllocationPayload) => 
      projectsApi.createFromAllocation(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      queryClient.invalidateQueries({ queryKey: ['land-allocations'] });
    },
  });
}

export function useFieldCycleSetup() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: FieldCycleSetupPayload) => projectsApi.fieldCycleSetup(payload),
    onSuccess: (project) => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      queryClient.invalidateQueries({ queryKey: ['projects', project.id] });
      queryClient.invalidateQueries({ queryKey: ['land-allocations'] });
    },
  });
}

export function useUpdateProject() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<CreateProjectPayload> }) =>
      projectsApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      queryClient.invalidateQueries({ queryKey: ['projects', variables.id] });
    },
  });
}

export function useDeleteProject() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
    },
  });
}

export function useCloseProject() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.close(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      queryClient.invalidateQueries({ queryKey: ['projects', id] });
    },
  });
}

export function useReopenProject() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.reopen(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      queryClient.invalidateQueries({ queryKey: ['projects', id] });
    },
  });
}
