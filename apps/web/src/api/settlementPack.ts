import { apiClient } from '@farm-erp/shared';
import type { SettlementPackResponse } from '@farm-erp/shared';

export const settlementPackApi = {
  generate: (projectId: string, registerVersion?: string) =>
    apiClient.post<SettlementPackResponse>(
      `/api/projects/${projectId}/settlement-pack`,
      registerVersion ? { register_version: registerVersion } : {}
    ),
  get: (id: string) => apiClient.get<SettlementPackResponse>(`/api/settlement-packs/${id}`),
};
