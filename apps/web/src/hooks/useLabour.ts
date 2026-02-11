import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { labourApi, type WorkerFilters, type WorkLogFilters } from '../api/labour';
import type {
  LabWorkLog,
  CreateLabWorkerPayload,
  UpdateLabWorkerPayload,
  CreateLabWorkLogPayload,
  UpdateLabWorkLogPayload,
  PostLabWorkLogRequest,
  ReverseLabWorkLogRequest,
} from '../types';
import toast from 'react-hot-toast';

export function useWorkers(f?: WorkerFilters) {
  return useQuery({
    queryKey: ['labour', 'workers', f],
    queryFn: () => labourApi.workers.list(f),
    staleTime: 10 * 60 * 1000, // 10 minutes - reference data
    gcTime: 30 * 60 * 1000,
  });
}

export function useWorker(id: string) {
  return useQuery({
    queryKey: ['labour', 'workers', id],
    queryFn: () => labourApi.workers.get(id),
    enabled: !!id,
  });
}

export function useCreateWorker() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateLabWorkerPayload) => labourApi.workers.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['labour', 'workers'] });
      qc.invalidateQueries({ queryKey: ['labour', 'payables'] });
      toast.success('Worker created');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error || (e as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Failed to create worker'),
  });
}

export function useUpdateWorker() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateLabWorkerPayload }) => labourApi.workers.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['labour', 'workers'] });
      qc.invalidateQueries({ queryKey: ['labour', 'workers', v.id] });
      toast.success('Worker updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error || (e as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Failed to update worker'),
  });
}

export function useWorkLogs(f?: WorkLogFilters) {
  return useQuery<LabWorkLog[], Error>({
    queryKey: ['labour', 'work-logs', f],
    queryFn: () => labourApi.workLogs.list(f),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
  });
}

export function useWorkLog(id: string) {
  return useQuery({
    queryKey: ['labour', 'work-logs', id],
    queryFn: () => labourApi.workLogs.get(id),
    enabled: !!id,
  });
}

export function useCreateWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateLabWorkLogPayload) => labourApi.workLogs.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['labour', 'work-logs'] });
      toast.success('Work log created');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error || (e as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Failed to create work log'),
  });
}

export function useUpdateWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateLabWorkLogPayload }) => labourApi.workLogs.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['labour', 'work-logs'] });
      qc.invalidateQueries({ queryKey: ['labour', 'work-logs', v.id] });
      toast.success('Work log updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error || (e as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Failed to update work log'),
  });
}

export function usePostWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostLabWorkLogRequest }) => labourApi.workLogs.post(id, payload),
    onSuccess: (_, variables) => {
      qc.invalidateQueries({ queryKey: ['labour', 'work-logs'] });
      qc.invalidateQueries({ queryKey: ['labour', 'work-logs', variables.id] });
      qc.invalidateQueries({ queryKey: ['labour', 'payables'] });
      toast.success('Work log posted');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error || (e as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Failed to post work log'),
  });
}

export function useReverseWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseLabWorkLogRequest }) => labourApi.workLogs.reverse(id, payload),
    onSuccess: (_, variables) => {
      qc.invalidateQueries({ queryKey: ['labour', 'work-logs'] });
      qc.invalidateQueries({ queryKey: ['labour', 'work-logs', variables.id] });
      qc.invalidateQueries({ queryKey: ['labour', 'payables'] });
      toast.success('Work log reversed');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error || (e as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Failed to reverse work log'),
  });
}

export function usePayablesOutstanding() {
  return useQuery({
    queryKey: ['labour', 'payables', 'outstanding'],
    queryFn: () => labourApi.payables.outstanding(),
  });
}
