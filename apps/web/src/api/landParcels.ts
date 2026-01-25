import { apiClient } from '@farm-erp/shared';
import type { LandParcel, LandParcelDetail, LandDocument, CreateLandParcelPayload, CreateLandDocumentPayload } from '../types';

export const landParcelsApi = {
  list: () => apiClient.get<LandParcel[]>('/api/land-parcels'),
  get: (id: string) => apiClient.get<LandParcelDetail>(`/api/land-parcels/${id}`),
  create: (payload: CreateLandParcelPayload) => apiClient.post<LandParcel>('/api/land-parcels', payload),
  update: (id: string, payload: Partial<CreateLandParcelPayload>) => 
    apiClient.patch<LandParcel>(`/api/land-parcels/${id}`, payload),
  delete: (id: string) => apiClient.delete(`/api/land-parcels/${id}`),
  listDocuments: (id: string) => apiClient.get<LandDocument[]>(`/api/land-parcels/${id}/documents`),
  addDocument: (id: string, payload: CreateLandDocumentPayload) => 
    apiClient.post<LandDocument>(`/api/land-parcels/${id}/documents`, payload),
};
