import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { landParcelsApi } from '../api/landParcels';
import type { CreateLandParcelPayload, CreateLandDocumentPayload } from '../types';

export function useLandParcels() {
  return useQuery({
    queryKey: ['land-parcels'],
    queryFn: () => landParcelsApi.list(),
  });
}

export function useLandParcel(id: string) {
  return useQuery({
    queryKey: ['land-parcels', id],
    queryFn: () => landParcelsApi.get(id),
    enabled: !!id,
  });
}

export function useCreateLandParcel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateLandParcelPayload) => landParcelsApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['land-parcels'] });
    },
  });
}

export function useUpdateLandParcel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<CreateLandParcelPayload> }) =>
      landParcelsApi.update(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-parcels'] });
      queryClient.invalidateQueries({ queryKey: ['land-parcels', variables.id] });
    },
  });
}

export function useDeleteLandParcel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => landParcelsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['land-parcels'] });
    },
  });
}

export function useLandParcelDocuments(landParcelId: string) {
  return useQuery({
    queryKey: ['land-parcels', landParcelId, 'documents'],
    queryFn: () => landParcelsApi.listDocuments(landParcelId),
    enabled: !!landParcelId,
  });
}

export function useAddLandParcelDocument() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CreateLandDocumentPayload }) =>
      landParcelsApi.addDocument(id, payload),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['land-parcels', variables.id] });
      queryClient.invalidateQueries({ queryKey: ['land-parcels', variables.id, 'documents'] });
    },
  });
}
