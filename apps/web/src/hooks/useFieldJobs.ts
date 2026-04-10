import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { fieldJobsApi, type FieldJobFilters } from '../api/fieldJobs';
import type {
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
} from '../types';
import toast from 'react-hot-toast';

function err(e: unknown): string {
  return (
    (e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error ||
    (e as { response?: { data?: { message?: string } } })?.response?.data?.message ||
    (e as Error)?.message ||
    'Request failed'
  );
}

function fieldJobKeys(f?: FieldJobFilters) {
  return ['crop-ops', 'field-jobs', f] as const;
}

export function useFieldJobs(f?: FieldJobFilters) {
  return useQuery({
    queryKey: fieldJobKeys(f),
    queryFn: () => fieldJobsApi.list(f),
    staleTime: 20 * 1000,
    gcTime: 2 * 60 * 1000,
  });
}

export function useFieldJob(id: string) {
  return useQuery({
    queryKey: ['crop-ops', 'field-jobs', id],
    queryFn: () => fieldJobsApi.get(id),
    enabled: !!id,
  });
}

export function useCreateFieldJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateFieldJobPayload) => fieldJobsApi.create(p),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', data.id] });
      toast.success('Field job created');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useUpdateFieldJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateFieldJobPayload }) =>
      fieldJobsApi.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', v.id] });
      toast.success('Field job updated');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useAddFieldJobInput() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AddFieldJobInputPayload }) =>
      fieldJobsApi.addInput(id, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', variables.id] });
      toast.success('Input line added');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useUpdateFieldJobInput() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      lineId,
      payload,
    }: {
      id: string;
      lineId: string;
      payload: UpdateFieldJobInputPayload;
    }) => fieldJobsApi.updateInput(id, lineId, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', variables.id] });
      toast.success('Input line updated');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useDeleteFieldJobInput() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, lineId }: { id: string; lineId: string }) => {
      await fieldJobsApi.deleteInput(id, lineId);
      return { id };
    },
    onSuccess: ({ id }) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', id] });
      toast.success('Input line removed');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useAddFieldJobLabour() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AddFieldJobLabourPayload }) =>
      fieldJobsApi.addLabour(id, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', variables.id] });
      toast.success('Labour line added');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useUpdateFieldJobLabour() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      lineId,
      payload,
    }: {
      id: string;
      lineId: string;
      payload: UpdateFieldJobLabourPayload;
    }) => fieldJobsApi.updateLabour(id, lineId, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', variables.id] });
      toast.success('Labour line updated');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useDeleteFieldJobLabour() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, lineId }: { id: string; lineId: string }) => {
      await fieldJobsApi.deleteLabour(id, lineId);
      return { id };
    },
    onSuccess: ({ id }) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', id] });
      toast.success('Labour line removed');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useAddFieldJobMachine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AddFieldJobMachinePayload }) =>
      fieldJobsApi.addMachine(id, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', variables.id] });
      toast.success('Machine line added');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useUpdateFieldJobMachine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      lineId,
      payload,
    }: {
      id: string;
      lineId: string;
      payload: UpdateFieldJobMachinePayload;
    }) => fieldJobsApi.updateMachine(id, lineId, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', variables.id] });
      toast.success('Machine line updated');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useDeleteFieldJobMachine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, lineId }: { id: string; lineId: string }) => {
      await fieldJobsApi.deleteMachine(id, lineId);
      return { id };
    },
    onSuccess: ({ id }) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', id] });
      toast.success('Machine line removed');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function usePostFieldJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostFieldJobRequest }) =>
      fieldJobsApi.post(id, payload),
    onSuccess: (_, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', variables.id] });
      toast.success('Field job posted');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useReverseFieldJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseFieldJobRequest }) =>
      fieldJobsApi.reverse(id, payload),
    onSuccess: (_, variables) => {
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs'] });
      qc.invalidateQueries({ queryKey: ['crop-ops', 'field-jobs', variables.id] });
      toast.success('Field job reversed');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}
