import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { cropOpsApi, type ActivityTypeFilters, type ActivityFilters } from '../api/cropOps';
import type {
  CropActivity,
  CreateActivityTypePayload,
  UpdateActivityTypePayload,
  CreateCropActivityPayload,
  UpdateCropActivityPayload,
  PostCropActivityRequest,
  ReverseCropActivityRequest,
} from '../types';
import toast from 'react-hot-toast';

function err(e: unknown): string {
  return (e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error
    || (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    || 'Request failed';
}

export function useActivityTypes(f?: ActivityTypeFilters) {
  return useQuery({
    queryKey: ['crop-ops', 'activity-types', f],
    queryFn: () => cropOpsApi.activityTypes.list(f),
    staleTime: 10 * 60 * 1000, // 10 minutes - reference data
    gcTime: 30 * 60 * 1000,
  });
}

export function useCreateActivityType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateActivityTypePayload) => cropOpsApi.activityTypes.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activity-types'] });
      toast.success('Activity type created');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useUpdateActivityType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateActivityTypePayload }) => cropOpsApi.activityTypes.update(id, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activity-types'] });
      toast.success('Activity type updated');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useActivities(f?: ActivityFilters) {
  return useQuery<CropActivity[], Error>({
    queryKey: ['crop-ops', 'activities', f],
    queryFn: () => cropOpsApi.activities.list(f),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
  });
}

export function useActivitiesTimeline(f?: ActivityFilters) {
  return useQuery({
    queryKey: ['crop-ops', 'activities', 'timeline', f],
    queryFn: () => cropOpsApi.activities.timeline(f),
  });
}

export function useActivity(id: string) {
  return useQuery({
    queryKey: ['crop-ops', 'activities', id],
    queryFn: () => cropOpsApi.activities.get(id),
    enabled: !!id,
  });
}

export function useCreateActivity() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateCropActivityPayload) => cropOpsApi.activities.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activities'] });
      toast.success('Activity created');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useUpdateActivity() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateCropActivityPayload }) => cropOpsApi.activities.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activities'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activities', v.id] });
      toast.success('Activity updated');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function usePostActivity() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostCropActivityRequest }) => cropOpsApi.activities.post(id, payload),
    onSuccess: (_, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activities'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activities', variables.id] });
      toast.success('Activity posted');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useReverseActivity() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseCropActivityRequest }) => cropOpsApi.activities.reverse(id, payload),
    onSuccess: (_, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activities'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'activities', variables.id] });
      toast.success('Activity reversed');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}
