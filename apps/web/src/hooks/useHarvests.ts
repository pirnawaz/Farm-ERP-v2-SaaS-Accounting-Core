import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { harvestsApi, type HarvestFilters } from '../api/harvests';
import type {
  CreateHarvestPayload,
  UpdateHarvestPayload,
  PostHarvestPayload,
  ReverseHarvestPayload,
} from '../types';
import toast from 'react-hot-toast';

function err(e: unknown): string {
  return (e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error
    || (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    || 'Request failed';
}

export function useHarvests(f?: HarvestFilters) {
  return useQuery({
    queryKey: ['harvests', f],
    queryFn: () => harvestsApi.list(f),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
    keepPreviousData: true, // Prevent flicker on filter changes
  });
}

export function useHarvest(id: string) {
  return useQuery({
    queryKey: ['harvests', id],
    queryFn: () => harvestsApi.get(id),
    enabled: !!id,
  });
}

export function useCreateHarvest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateHarvestPayload) => harvestsApi.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['harvests'] });
      toast.success('Harvest created');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useUpdateHarvest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateHarvestPayload }) => harvestsApi.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['harvests'] });
      qc.invalidateQueries({ queryKey: ['harvests', v.id] });
      toast.success('Harvest updated');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useAddHarvestLine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: { inventory_item_id: string; store_id: string; quantity: number; uom?: string; notes?: string } }) => 
      harvestsApi.addLine(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['harvests', v.id] });
      toast.success('Line added');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useUpdateHarvestLine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, lineId, payload }: { id: string; lineId: string; payload: { inventory_item_id?: string; store_id?: string; quantity?: number; uom?: string; notes?: string } }) => 
      harvestsApi.updateLine(id, lineId, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['harvests', v.id] });
      toast.success('Line updated');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useDeleteHarvestLine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, lineId }: { id: string; lineId: string }) => harvestsApi.deleteLine(id, lineId),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['harvests', v.id] });
      toast.success('Line deleted');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function usePostHarvest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostHarvestPayload }) => harvestsApi.post(id, payload),
    onSuccess: (_, variables) => {
      qc.invalidateQueries({ queryKey: ['harvests'] });
      qc.invalidateQueries({ queryKey: ['harvests', variables.id] });
      toast.success('Harvest posted');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}

export function useReverseHarvest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseHarvestPayload }) => harvestsApi.reverse(id, payload),
    onSuccess: (_, variables) => {
      qc.invalidateQueries({ queryKey: ['harvests'] });
      qc.invalidateQueries({ queryKey: ['harvests', variables.id] });
      toast.success('Harvest reversed');
    },
    onError: (e: unknown) => toast.error(err(e)),
  });
}
