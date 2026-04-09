import { apiClient } from '@farm-erp/shared';
import type {
  CreateFixedAssetPayload,
  FixedAsset,
  FixedAssetDepreciationRun,
  FixedAssetDisposal,
  PostingGroup,
} from '@farm-erp/shared';

function qs(params: Record<string, string | undefined>): string {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== '') search.set(k, v);
  });
  const q = search.toString();
  return q ? `?${q}` : '';
}

export const fixedAssetsApi = {
  list: (opts?: { status?: string }) =>
    apiClient.get<FixedAsset[]>(`/api/fixed-assets${qs({ status: opts?.status })}`),

  get: (id: string) => apiClient.get<FixedAsset>(`/api/fixed-assets/${id}`),

  create: (body: CreateFixedAssetPayload) =>
    apiClient.post<FixedAsset>('/api/fixed-assets', body),

  activate: (
    id: string,
    body: {
      posting_date: string;
      idempotency_key: string;
      source_account: 'BANK' | 'CASH' | 'AP_CLEARING' | 'EQUITY_INJECTION';
    }
  ) => apiClient.post<PostingGroup>(`/api/fixed-assets/${id}/activate`, body),

  listDepreciationRuns: (opts?: { status?: string }) =>
    apiClient.get<FixedAssetDepreciationRun[]>(
      `/api/fixed-asset-depreciation-runs${qs({ status: opts?.status })}`
    ),

  createDepreciationRun: (body: { period_start: string; period_end: string }) =>
    apiClient.post<FixedAssetDepreciationRun>('/api/fixed-asset-depreciation-runs', body),

  getDepreciationRun: (id: string) =>
    apiClient.get<FixedAssetDepreciationRun>(`/api/fixed-asset-depreciation-runs/${id}`),

  postDepreciationRun: (
    id: string,
    body: { posting_date: string; idempotency_key: string }
  ) => apiClient.post<PostingGroup>(`/api/fixed-asset-depreciation-runs/${id}/post`, body),

  createDisposal: (
    assetId: string,
    body: {
      disposal_date: string;
      proceeds_amount: number;
      proceeds_account?: 'BANK' | 'CASH' | null;
      notes?: string | null;
    }
  ) => apiClient.post<FixedAssetDisposal>(`/api/fixed-assets/${assetId}/disposals`, body),

  getDisposal: (id: string) =>
    apiClient.get<FixedAssetDisposal>(`/api/fixed-asset-disposals/${id}`),

  postDisposal: (id: string, body: { posting_date: string; idempotency_key: string }) =>
    apiClient.post<PostingGroup>(`/api/fixed-asset-disposals/${id}/post`, body),
};
