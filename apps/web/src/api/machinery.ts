import { apiClient } from '@farm-erp/shared';
import type {
  Machine,
  MachineMaintenanceType,
  MachineWorkLog,
  MachineRateCard,
  MachineryCharge,
  CreateMachinePayload,
  UpdateMachinePayload,
  CreateMachineMaintenanceTypePayload,
  UpdateMachineMaintenanceTypePayload,
  CreateMachineWorkLogPayload,
  UpdateMachineWorkLogPayload,
  CreateMachineRateCardPayload,
  UpdateMachineRateCardPayload,
  PostMachineWorkLogRequest,
  ReverseMachineWorkLogRequest,
  MachineWorkLogPostResult,
  GenerateChargesPayload,
  UpdateChargePayload,
  PostChargeRequest,
  ReverseChargeRequest,
  ChargePostResult,
  MachineMaintenanceJob,
  CreateMachineMaintenanceJobPayload,
  UpdateMachineMaintenanceJobPayload,
  PostMachineMaintenanceJobRequest,
  ReverseMachineMaintenanceJobRequest,
  PostMachineMaintenanceJobResult,
  MachineryService,
  CreateMachineryServicePayload,
  UpdateMachineryServicePayload,
  PostMachineryServiceRequest,
  ReverseMachineryServiceRequest,
  PostMachineryServiceResult,
  MachineryProfitabilityRow,
  MachineryChargesByMachineRow,
  MachineryCostsByMachineRow,
} from '../types';

const BASE = '/api/v1/machinery';

export interface MachineFilters {
  status?: string;
  machine_type?: string;
  ownership_type?: string;
}

export interface MaintenanceTypeFilters {
  is_active?: boolean;
}

export interface WorkLogFilters {
  status?: string;
  machine_id?: string;
  crop_cycle_id?: string;
  project_id?: string;
  from?: string;
  to?: string;
}

export interface RateCardFilters {
  machine_id?: string;
  machine_type?: string;
  rate_unit?: 'HOUR' | 'KM' | 'JOB';
  date?: string;
  is_active?: boolean;
}

export interface ChargeFilters {
  project_id?: string;
  crop_cycle_id?: string;
  status?: 'DRAFT' | 'POSTED' | 'REVERSED';
  from?: string;
  to?: string;
  landlord_party_id?: string;
}

export interface MaintenanceJobFilters {
  machine_id?: string;
  status?: 'DRAFT' | 'POSTED' | 'REVERSED';
  from?: string;
  to?: string;
  vendor_party_id?: string;
}

export interface MachineryServiceFilters {
  machine_id?: string;
  project_id?: string;
  status?: 'DRAFT' | 'POSTED' | 'REVERSED';
  from?: string;
  to?: string;
}

export interface ProfitabilityReportFilters {
  from: string;
  to: string;
}

function searchParams(obj: Record<string, string | boolean | undefined> | object): string {
  const r = (obj || {}) as Record<string, string | boolean | undefined>;
  const p = new URLSearchParams();
  Object.entries(r).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') p.append(k, String(v));
  });
  const s = p.toString();
  return s ? `?${s}` : '';
}

export const machineryApi = {
  machines: {
    list: (f?: MachineFilters) =>
      apiClient.get<Machine[]>(`${BASE}/machines${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<Machine>(`${BASE}/machines/${id}`),
    create: (payload: CreateMachinePayload) =>
      apiClient.post<Machine>(`${BASE}/machines`, payload),
    update: (id: string, payload: UpdateMachinePayload) =>
      apiClient.put<Machine>(`${BASE}/machines/${id}`, payload),
  },
  maintenanceTypes: {
    list: (f?: MaintenanceTypeFilters) =>
      apiClient.get<MachineMaintenanceType[]>(
        `${BASE}/maintenance-types${searchParams(f || {})}`
      ),
    create: (payload: CreateMachineMaintenanceTypePayload) =>
      apiClient.post<MachineMaintenanceType>(`${BASE}/maintenance-types`, payload),
    update: (id: string, payload: UpdateMachineMaintenanceTypePayload) =>
      apiClient.put<MachineMaintenanceType>(`${BASE}/maintenance-types/${id}`, payload),
  },
  workLogs: {
    list: (f?: WorkLogFilters) =>
      apiClient.get<MachineWorkLog[]>(`${BASE}/work-logs${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<MachineWorkLog>(`${BASE}/work-logs/${id}`),
    create: (payload: CreateMachineWorkLogPayload) =>
      apiClient.post<MachineWorkLog>(`${BASE}/work-logs`, payload),
    update: (id: string, payload: UpdateMachineWorkLogPayload) =>
      apiClient.put<MachineWorkLog>(`${BASE}/work-logs/${id}`, payload),
    delete: (id: string) => apiClient.delete<void>(`${BASE}/work-logs/${id}`),
    post: (id: string, payload: PostMachineWorkLogRequest) =>
      apiClient.post<MachineWorkLogPostResult>(`${BASE}/work-logs/${id}/post`, payload),
    reverse: (id: string, payload: ReverseMachineWorkLogRequest) =>
      apiClient.post<MachineWorkLogPostResult>(`${BASE}/work-logs/${id}/reverse`, payload),
  },
  rateCards: {
    list: (f?: RateCardFilters) =>
      apiClient.get<MachineRateCard[]>(`${BASE}/rate-cards${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<MachineRateCard>(`${BASE}/rate-cards/${id}`),
    create: (payload: CreateMachineRateCardPayload) =>
      apiClient.post<MachineRateCard>(`${BASE}/rate-cards`, payload),
    update: (id: string, payload: UpdateMachineRateCardPayload) =>
      apiClient.put<MachineRateCard>(`${BASE}/rate-cards/${id}`, payload),
  },
  charges: {
    list: (f?: ChargeFilters) =>
      apiClient.get<MachineryCharge[]>(`${BASE}/charges${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<MachineryCharge>(`${BASE}/charges/${id}`),
    generate: (payload: GenerateChargesPayload) =>
      apiClient.post<MachineryCharge | MachineryCharge[]>(`${BASE}/charges/generate`, payload),
    update: (id: string, payload: UpdateChargePayload) =>
      apiClient.put<MachineryCharge>(`${BASE}/charges/${id}`, payload),
    post: (id: string, payload: PostChargeRequest) =>
      apiClient.post<ChargePostResult>(`${BASE}/charges/${id}/post`, payload),
    reverse: (id: string, payload: ReverseChargeRequest) =>
      apiClient.post<ChargePostResult>(`${BASE}/charges/${id}/reverse`, payload),
  },
  maintenanceJobs: {
    list: (f?: MaintenanceJobFilters) =>
      apiClient.get<MachineMaintenanceJob[]>(`${BASE}/maintenance-jobs${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<MachineMaintenanceJob>(`${BASE}/maintenance-jobs/${id}`),
    create: (payload: CreateMachineMaintenanceJobPayload) =>
      apiClient.post<MachineMaintenanceJob>(`${BASE}/maintenance-jobs`, payload),
    update: (id: string, payload: UpdateMachineMaintenanceJobPayload) =>
      apiClient.put<MachineMaintenanceJob>(`${BASE}/maintenance-jobs/${id}`, payload),
    delete: (id: string) => apiClient.delete<void>(`${BASE}/maintenance-jobs/${id}`),
    post: (id: string, payload: PostMachineMaintenanceJobRequest) =>
      apiClient.post<PostMachineMaintenanceJobResult>(`${BASE}/maintenance-jobs/${id}/post`, payload),
    reverse: (id: string, payload: ReverseMachineMaintenanceJobRequest) =>
      apiClient.post<PostMachineMaintenanceJobResult>(`${BASE}/maintenance-jobs/${id}/reverse`, payload),
  },
  machineryServices: {
    list: (f?: MachineryServiceFilters) =>
      apiClient.get<MachineryService[]>(`${BASE}/machinery-services${searchParams(f || {})}`),
    get: (id: string) => apiClient.get<MachineryService>(`${BASE}/machinery-services/${id}`),
    create: (payload: CreateMachineryServicePayload) =>
      apiClient.post<MachineryService>(`${BASE}/machinery-services`, payload),
    update: (id: string, payload: UpdateMachineryServicePayload) =>
      apiClient.put<MachineryService>(`${BASE}/machinery-services/${id}`, payload),
    post: (id: string, payload: PostMachineryServiceRequest) =>
      apiClient.post<PostMachineryServiceResult>(`${BASE}/machinery-services/${id}/post`, payload),
    reverse: (id: string, payload: ReverseMachineryServiceRequest) =>
      apiClient.post<PostMachineryServiceResult>(`${BASE}/machinery-services/${id}/reverse`, payload),
  },
  reports: {
    profitability: (f: ProfitabilityReportFilters) =>
      apiClient.get<MachineryProfitabilityRow[]>(`${BASE}/reports/profitability${searchParams(f)}`),
    chargesByMachine: (f: ProfitabilityReportFilters) =>
      apiClient.get<MachineryChargesByMachineRow[]>(`${BASE}/reports/charges-by-machine${searchParams(f)}`),
    costsByMachine: (f: ProfitabilityReportFilters) =>
      apiClient.get<MachineryCostsByMachineRow[]>(`${BASE}/reports/costs-by-machine${searchParams(f)}`),
  },
};

// Convenience aliases matching the requested function names
export const listMachines = machineryApi.machines.list;
export const getMachine = machineryApi.machines.get;
export const createMachine = machineryApi.machines.create;
export const updateMachine = machineryApi.machines.update;

export const listMaintenanceTypes = machineryApi.maintenanceTypes.list;
export const createMaintenanceType = machineryApi.maintenanceTypes.create;
export const updateMaintenanceType = machineryApi.maintenanceTypes.update;

export const listWorkLogs = machineryApi.workLogs.list;
export const getWorkLog = machineryApi.workLogs.get;
export const createWorkLog = machineryApi.workLogs.create;
export const updateWorkLog = machineryApi.workLogs.update;
export const deleteWorkLog = machineryApi.workLogs.delete;
export const postWorkLog = machineryApi.workLogs.post;
export const reverseWorkLog = machineryApi.workLogs.reverse;

export const listRateCards = machineryApi.rateCards.list;
export const getRateCard = machineryApi.rateCards.get;
export const createRateCard = machineryApi.rateCards.create;
export const updateRateCard = machineryApi.rateCards.update;

export const listCharges = machineryApi.charges.list;
export const getCharge = machineryApi.charges.get;
export const generateCharges = machineryApi.charges.generate;
export const updateCharge = machineryApi.charges.update;
export const postCharge = machineryApi.charges.post;
export const reverseCharge = machineryApi.charges.reverse;

export const listMaintenanceJobs = machineryApi.maintenanceJobs.list;
export const getMaintenanceJob = machineryApi.maintenanceJobs.get;
export const createMaintenanceJob = machineryApi.maintenanceJobs.create;
export const updateMaintenanceJob = machineryApi.maintenanceJobs.update;
export const deleteMaintenanceJob = machineryApi.maintenanceJobs.delete;
export const postMaintenanceJob = machineryApi.maintenanceJobs.post;
export const reverseMaintenanceJob = machineryApi.maintenanceJobs.reverse;
