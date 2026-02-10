import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import {
  machineryApi,
  type MachineFilters,
  type MaintenanceTypeFilters,
  type WorkLogFilters,
  type RateCardFilters,
  type ChargeFilters,
  type MaintenanceJobFilters,
  type MachineryServiceFilters,
  type ProfitabilityReportFilters,
} from '../api/machinery';
import type {
  CreateMachinePayload,
  UpdateMachinePayload,
  CreateMachineMaintenanceTypePayload,
  UpdateMachineMaintenanceTypePayload,
  CreateMachineWorkLogPayload,
  UpdateMachineWorkLogPayload,
  CreateMachineRateCardPayload,
  UpdateMachineRateCardPayload,
  GenerateChargesPayload,
  UpdateChargePayload,
  PostChargeRequest,
  ReverseChargeRequest,
  CreateMachineMaintenanceJobPayload,
  UpdateMachineMaintenanceJobPayload,
  PostMachineMaintenanceJobRequest,
  ReverseMachineMaintenanceJobRequest,
  CreateMachineryServicePayload,
  UpdateMachineryServicePayload,
  PostMachineryServiceRequest,
  ReverseMachineryServiceRequest,
} from '../types';
import toast from 'react-hot-toast';

const err = (e: unknown) =>
  (e as { response?: { data?: { error?: string; message?: string } } })?.response?.data
    ?.error ||
  (e as { response?: { data?: { message?: string } } })?.response?.data?.message;

// Machines
export function useMachinesQuery(f?: MachineFilters) {
  return useQuery({
    queryKey: ['machinery', 'machines', f],
    queryFn: () => machineryApi.machines.list(f),
    staleTime: 10 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
  });
}

export function useMachineQuery(id: string) {
  return useQuery({
    queryKey: ['machinery', 'machines', id],
    queryFn: () => machineryApi.machines.get(id),
    enabled: !!id,
  });
}

export function useCreateMachine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateMachinePayload) => machineryApi.machines.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['machinery', 'machines'] });
      toast.success('Machine created');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to create machine'),
  });
}

export function useUpdateMachine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateMachinePayload }) =>
      machineryApi.machines.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'machines'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'machines', v.id] });
      toast.success('Machine updated');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to update machine'),
  });
}

// Maintenance Types
export function useMaintenanceTypesQuery(f?: MaintenanceTypeFilters) {
  return useQuery({
    queryKey: ['machinery', 'maintenance-types', f],
    queryFn: () => machineryApi.maintenanceTypes.list(f),
    staleTime: 10 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
  });
}

export function useCreateMaintenanceType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateMachineMaintenanceTypePayload) =>
      machineryApi.maintenanceTypes.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-types'] });
      toast.success('Maintenance type created');
    },
    onError: (e: unknown) =>
      toast.error(err(e) || 'Failed to create maintenance type'),
  });
}

export function useUpdateMaintenanceType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: UpdateMachineMaintenanceTypePayload;
    }) => machineryApi.maintenanceTypes.update(id, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-types'] });
      toast.success('Maintenance type updated');
    },
    onError: (e: unknown) =>
      toast.error(err(e) || 'Failed to update maintenance type'),
  });
}

// Work Logs
export function useWorkLogsQuery(f?: WorkLogFilters) {
  return useQuery({
    queryKey: ['machinery', 'work-logs', f],
    queryFn: () => machineryApi.workLogs.list(f),
    staleTime: 20 * 1000,
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function useWorkLogQuery(id: string) {
  return useQuery({
    queryKey: ['machinery', 'work-logs', id],
    queryFn: () => machineryApi.workLogs.get(id),
    enabled: !!id,
  });
}

export function useCreateWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateMachineWorkLogPayload) => machineryApi.workLogs.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs'] });
      toast.success('Work log created');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to create work log'),
  });
}

export function useUpdateWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: { id: string; payload: UpdateMachineWorkLogPayload }) =>
      machineryApi.workLogs.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs', v.id] });
      toast.success('Work log updated');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to update work log'),
  });
}

export function useDeleteWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => machineryApi.workLogs.delete(id),
    onSuccess: (_, id) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs', id] });
      toast.success('Work log deleted');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to delete work log'),
  });
}

export function usePostWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      posting_date,
      idempotency_key,
    }: {
      id: string;
      posting_date: string;
      idempotency_key?: string;
    }) =>
      machineryApi.workLogs.post(id, {
        posting_date,
        idempotency_key,
      }),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs', v.id] });
      toast.success('Work log posted');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to post work log'),
  });
}

export function useReverseWorkLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      posting_date,
      reason,
    }: {
      id: string;
      posting_date: string;
      reason?: string | null;
    }) =>
      machineryApi.workLogs.reverse(id, {
        posting_date,
        reason: reason ?? undefined,
      }),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'work-logs', v.id] });
      toast.success('Work log reversed');
    },
    onError: (e: unknown) =>
      toast.error(err(e) || 'Failed to reverse work log'),
  });
}

// Rate Cards
export function useRateCardsQuery(f?: RateCardFilters) {
  return useQuery({
    queryKey: ['machinery', 'rate-cards', f],
    queryFn: () => machineryApi.rateCards.list(f),
    staleTime: 10 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
  });
}

export function useRateCardQuery(id: string) {
  return useQuery({
    queryKey: ['machinery', 'rate-cards', id],
    queryFn: () => machineryApi.rateCards.get(id),
    enabled: !!id,
  });
}

export function useCreateRateCard() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateMachineRateCardPayload) => machineryApi.rateCards.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['machinery', 'rate-cards'] });
      toast.success('Rate card created');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to create rate card'),
  });
}

export function useUpdateRateCard() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateMachineRateCardPayload }) =>
      machineryApi.rateCards.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'rate-cards'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'rate-cards', v.id] });
      toast.success('Rate card updated');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to update rate card'),
  });
}

// Charges
export function useChargesQuery(f?: ChargeFilters) {
  return useQuery({
    queryKey: ['machinery', 'charges', f],
    queryFn: () => machineryApi.charges.list(f),
    staleTime: 20 * 1000,
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function useChargeQuery(id: string) {
  return useQuery({
    queryKey: ['machinery', 'charges', id],
    queryFn: () => machineryApi.charges.get(id),
    enabled: !!id,
  });
}

export function useGenerateCharges() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: GenerateChargesPayload) => machineryApi.charges.generate(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['machinery', 'charges'] });
      toast.success('Charges generated');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to generate charges'),
  });
}

export function useUpdateCharge() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateChargePayload }) =>
      machineryApi.charges.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'charges'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'charges', v.id] });
      toast.success('Charge updated');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to update charge'),
  });
}

export function usePostCharge() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: PostChargeRequest;
    }) => machineryApi.charges.post(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'charges'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'charges', v.id] });
      toast.success('Charge posted');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to post charge'),
  });
}

export function useReverseCharge() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: ReverseChargeRequest;
    }) => machineryApi.charges.reverse(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'charges'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'charges', v.id] });
      toast.success('Charge reversed');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to reverse charge'),
  });
}

// Maintenance Jobs
export function useMaintenanceJobsQuery(f?: MaintenanceJobFilters) {
  return useQuery({
    queryKey: ['machinery', 'maintenance-jobs', f],
    queryFn: () => machineryApi.maintenanceJobs.list(f),
    staleTime: 20 * 1000,
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function useMaintenanceJobQuery(id: string) {
  return useQuery({
    queryKey: ['machinery', 'maintenance-jobs', id],
    queryFn: () => machineryApi.maintenanceJobs.get(id),
    enabled: !!id,
  });
}

export function useCreateMaintenanceJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateMachineMaintenanceJobPayload) => machineryApi.maintenanceJobs.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs'] });
      toast.success('Maintenance job created');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to create maintenance job'),
  });
}

export function useUpdateMaintenanceJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateMachineMaintenanceJobPayload }) =>
      machineryApi.maintenanceJobs.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs', v.id] });
      toast.success('Maintenance job updated');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to update maintenance job'),
  });
}

export function useDeleteMaintenanceJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => machineryApi.maintenanceJobs.delete(id),
    onSuccess: (_, id) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs', id] });
      toast.success('Maintenance job deleted');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to delete maintenance job'),
  });
}

export function usePostMaintenanceJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostMachineMaintenanceJobRequest }) =>
      machineryApi.maintenanceJobs.post(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs', v.id] });
      toast.success('Maintenance job posted');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to post maintenance job'),
  });
}

export function useReverseMaintenanceJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseMachineMaintenanceJobRequest }) =>
      machineryApi.maintenanceJobs.reverse(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'maintenance-jobs', v.id] });
      toast.success('Maintenance job reversed');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to reverse maintenance job'),
  });
}

// Machinery Services
export function useMachineryServicesQuery(f?: MachineryServiceFilters) {
  return useQuery({
    queryKey: ['machinery', 'machinery-services', f],
    queryFn: () => machineryApi.machineryServices.list(f),
    staleTime: 20 * 1000,
    gcTime: 2 * 60 * 1000,
  });
}

export function useMachineryServiceQuery(id: string) {
  return useQuery({
    queryKey: ['machinery', 'machinery-services', id],
    queryFn: () => machineryApi.machineryServices.get(id),
    enabled: !!id,
  });
}

export function useCreateMachineryService() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateMachineryServicePayload) => machineryApi.machineryServices.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['machinery', 'machinery-services'] });
      toast.success('Service created');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to create service'),
  });
}

export function useUpdateMachineryService() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateMachineryServicePayload }) =>
      machineryApi.machineryServices.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'machinery-services'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'machinery-services', v.id] });
      toast.success('Service updated');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to update service'),
  });
}

export function usePostMachineryService() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostMachineryServiceRequest }) =>
      machineryApi.machineryServices.post(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'machinery-services'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'machinery-services', v.id] });
      toast.success('Service posted');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to post service'),
  });
}

export function useReverseMachineryService() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseMachineryServiceRequest }) =>
      machineryApi.machineryServices.reverse(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['machinery', 'machinery-services'] });
      qc.invalidateQueries({ queryKey: ['machinery', 'machinery-services', v.id] });
      toast.success('Service reversed');
    },
    onError: (e: unknown) => toast.error(err(e) || 'Failed to reverse service'),
  });
}

// Reports
export function useMachineryProfitabilityQuery(f: ProfitabilityReportFilters) {
  return useQuery({
    queryKey: ['machinery', 'reports', 'profitability', f],
    queryFn: () => machineryApi.reports.profitability(f),
    enabled: !!f.from && !!f.to,
    staleTime: 5 * 60 * 1000, // 5 minutes
    gcTime: 30 * 60 * 1000,
  });
}
