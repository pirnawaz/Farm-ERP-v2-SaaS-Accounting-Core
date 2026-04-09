import { apiClient } from '@farm-erp/shared';
import type {
  SettlementPackResponse,
  SettlementPackListItem,
  SettlementPackRegisterPayload,
  SettlementPackGenerateVersionResponse,
  SettlementPackExportPdfResponse,
} from '@farm-erp/shared';

function buildQuery(params: Record<string, string | undefined>): string {
  const searchParams = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') {
      searchParams.append(key, value);
    }
  });
  const query = searchParams.toString();
  return query ? `?${query}` : '';
}

export const settlementPackApi = {
  list: (opts?: { status?: string }) =>
    apiClient.get<{ data: SettlementPackListItem[] }>(
      `/api/settlement-packs${buildQuery({ status: opts?.status })}`
    ),

  create: (body: { project_id: string; reference_no?: string; register_version?: string }) =>
    apiClient.post<SettlementPackResponse>('/api/settlement-packs', body),

  generate: (projectId: string, registerVersion?: string) =>
    apiClient.post<SettlementPackResponse>(
      `/api/projects/${projectId}/settlement-pack`,
      registerVersion ? { register_version: registerVersion } : {}
    ),

  get: (id: string) => apiClient.get<SettlementPackResponse>(`/api/settlement-packs/${id}`),

  getRegister: (id: string) =>
    apiClient.get<SettlementPackRegisterPayload>(`/api/settlement-packs/${id}/register`),

  generateVersion: (id: string) =>
    apiClient.post<SettlementPackGenerateVersionResponse>(
      `/api/settlement-packs/${id}/generate-version`,
      {}
    ),

  finalize: (id: string) =>
    apiClient.post<SettlementPackResponse>(`/api/settlement-packs/${id}/finalize`, {}),

  exportPdf: (id: string) =>
    apiClient.post<SettlementPackExportPdfResponse>(
      `/api/settlement-packs/${id}/export/pdf`,
      {}
    ),

  downloadPdfBlob: (id: string) => apiClient.getBlob(`/api/settlement-packs/${id}/pdf`),
};
