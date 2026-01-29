import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { inventoryApi, type GrnFilters, type IssueFilters, type TransferFilters, type AdjustmentFilters, type StockOnHandFilters, type StockMovementsFilters } from '../api/inventory';
import type {
  CreateInvGrnPayload,
  UpdateInvGrnPayload,
  PostInvGrnRequest,
  ReverseInvGrnRequest,
  CreateInvIssuePayload,
  UpdateInvIssuePayload,
  PostInvIssueRequest,
  ReverseInvIssueRequest,
  CreateInvTransferPayload,
  UpdateInvTransferPayload,
  PostInvTransferRequest,
  ReverseInvTransferRequest,
  CreateInvAdjustmentPayload,
  UpdateInvAdjustmentPayload,
  PostInvAdjustmentRequest,
  ReverseInvAdjustmentRequest,
} from '../types';
import toast from 'react-hot-toast';

// Items
export function useInventoryItems(is_active?: boolean) {
  return useQuery({
    queryKey: ['inventory', 'items', is_active],
    queryFn: () => inventoryApi.items.list(is_active),
    staleTime: 10 * 60 * 1000, // 10 minutes - reference data
    gcTime: 30 * 60 * 1000,
  });
}

export function useInventoryItem(id: string) {
  return useQuery({
    queryKey: ['inventory', 'items', id],
    queryFn: () => inventoryApi.items.get(id),
    enabled: !!id,
    staleTime: 5 * 60 * 1000, // 5 minutes
    gcTime: 30 * 60 * 1000,
  });
}

export function useCreateItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: { name: string; sku?: string; category_id?: string; uom_id: string; valuation_method?: string; is_active?: boolean }) =>
      inventoryApi.items.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'items'] });
      toast.success('Item created');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to create item'),
  });
}

export function useUpdateItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Parameters<typeof inventoryApi.items.update>[1] }) =>
      inventoryApi.items.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'items'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'items', v.id] });
      toast.success('Item updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to update item'),
  });
}

// Stores
export function useInventoryStores(is_active?: boolean) {
  return useQuery({
    queryKey: ['inventory', 'stores', is_active],
    queryFn: () => inventoryApi.stores.list(is_active),
    staleTime: 10 * 60 * 1000, // 10 minutes - reference data
    gcTime: 30 * 60 * 1000,
  });
}

export function useCreateStore() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: { name: string; type: 'MAIN' | 'FIELD' | 'OTHER'; is_active?: boolean }) => inventoryApi.stores.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'stores'] });
      toast.success('Store created');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to create store'),
  });
}

export function useUpdateStore() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Parameters<typeof inventoryApi.stores.update>[1] }) =>
      inventoryApi.stores.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'stores'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stores', v.id] });
      toast.success('Store updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to update store'),
  });
}

// UoMs
export function useUoms() {
  return useQuery({
    queryKey: ['inventory', 'uoms'],
    queryFn: () => inventoryApi.uoms.list(),
    staleTime: 15 * 60 * 1000, // 15 minutes - very stable reference data
    gcTime: 60 * 60 * 1000, // 1 hour
  });
}

export function useCreateUom() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: { code: string; name: string }) => inventoryApi.uoms.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'uoms'] }); toast.success('UoM created'); },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to create UoM'),
  });
}

// Categories
export function useCategories() {
  return useQuery({
    queryKey: ['inventory', 'categories'],
    queryFn: () => inventoryApi.categories.list(),
    staleTime: 15 * 60 * 1000, // 15 minutes - very stable reference data
    gcTime: 60 * 60 * 1000, // 1 hour
  });
}

export function useCreateCategory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: { name: string }) => inventoryApi.categories.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'categories'] }); toast.success('Category created'); },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to create category'),
  });
}

export function useUpdateCategory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<{ name: string }> }) =>
      inventoryApi.categories.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'categories'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'categories', v.id] });
      toast.success('Category updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to update category'),
  });
}

// GRNs
export function useGRNs(f?: GrnFilters) {
  return useQuery({
    queryKey: ['inventory', 'grns', f],
    queryFn: () => inventoryApi.grns.list(f),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function useGRN(id: string) {
  return useQuery({ queryKey: ['inventory', 'grns', id], queryFn: () => inventoryApi.grns.get(id), enabled: !!id });
}

export function useCreateGRN() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateInvGrnPayload) => inventoryApi.grns.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'grns'] }); toast.success('GRN created'); },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to create GRN'),
  });
}

export function useUpdateGRN() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateInvGrnPayload }) => inventoryApi.grns.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grns'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'grns', v.id] });
      toast.success('GRN updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to update GRN'),
  });
}

export function usePostGRN() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostInvGrnRequest }) => inventoryApi.grns.post(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grns'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'grns', v.id] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock'] });
      toast.success('GRN posted');
    },
    onError: (e: unknown) => {
      const d = (e as { response?: { data?: { error?: string; message?: string } } })?.response?.data;
      toast.error(d?.error || d?.message || 'Failed to post GRN');
    },
  });
}

export function useReverseGRN() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseInvGrnRequest }) => inventoryApi.grns.reverse(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'grns'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'grns', v.id] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock'] });
      toast.success('GRN reversed');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to reverse GRN'),
  });
}

// Issues
export function useIssues(f?: IssueFilters) {
  return useQuery({
    queryKey: ['inventory', 'issues', f],
    queryFn: () => inventoryApi.issues.list(f),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function useIssue(id: string) {
  return useQuery({ queryKey: ['inventory', 'issues', id], queryFn: () => inventoryApi.issues.get(id), enabled: !!id });
}

export function useCreateIssue() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateInvIssuePayload) => inventoryApi.issues.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'issues'] }); toast.success('Issue created'); },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to create Issue'),
  });
}

export function useUpdateIssue() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateInvIssuePayload }) => inventoryApi.issues.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'issues'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'issues', v.id] });
      toast.success('Issue updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to update Issue'),
  });
}

export function usePostIssue() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostInvIssueRequest }) => inventoryApi.issues.post(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'issues'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'issues', v.id] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock'] });
      toast.success('Issue posted');
    },
    onError: (e: unknown) => {
      const d = (e as { response?: { data?: { error?: string; message?: string } } })?.response?.data;
      toast.error(d?.error || d?.message || 'Failed to post Issue');
    },
  });
}

export function useReverseIssue() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseInvIssueRequest }) => inventoryApi.issues.reverse(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'issues'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'issues', v.id] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock'] });
      toast.success('Issue reversed');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to reverse Issue'),
  });
}

// Transfers
export function useTransfers(f?: TransferFilters) {
  return useQuery({
    queryKey: ['inventory', 'transfers', f],
    queryFn: () => inventoryApi.transfers.list(f),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function useTransfer(id: string) {
  return useQuery({ queryKey: ['inventory', 'transfers', id], queryFn: () => inventoryApi.transfers.get(id), enabled: !!id });
}

export function useCreateTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateInvTransferPayload) => inventoryApi.transfers.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'transfers'] }); toast.success('Transfer created'); },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to create transfer'),
  });
}

export function useUpdateTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateInvTransferPayload }) => inventoryApi.transfers.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'transfers'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'transfers', v.id] });
      toast.success('Transfer updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to update transfer'),
  });
}

export function usePostTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostInvTransferRequest }) => inventoryApi.transfers.post(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'transfers'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'transfers', v.id] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock'] });
      toast.success('Transfer posted');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to post transfer'),
  });
}

export function useReverseTransfer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseInvTransferRequest }) => inventoryApi.transfers.reverse(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'transfers'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'transfers', v.id] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock'] });
      toast.success('Transfer reversed');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to reverse transfer'),
  });
}

// Adjustments
export function useAdjustments(f?: AdjustmentFilters) {
  return useQuery({
    queryKey: ['inventory', 'adjustments', f],
    queryFn: () => inventoryApi.adjustments.list(f),
    staleTime: 20 * 1000, // 20 seconds - transactional data
    gcTime: 2 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}

export function useAdjustment(id: string) {
  return useQuery({ queryKey: ['inventory', 'adjustments', id], queryFn: () => inventoryApi.adjustments.get(id), enabled: !!id });
}

export function useCreateAdjustment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: CreateInvAdjustmentPayload) => inventoryApi.adjustments.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'adjustments'] }); toast.success('Adjustment created'); },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to create adjustment'),
  });
}

export function useUpdateAdjustment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateInvAdjustmentPayload }) => inventoryApi.adjustments.update(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'adjustments'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'adjustments', v.id] });
      toast.success('Adjustment updated');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to update adjustment'),
  });
}

export function usePostAdjustment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PostInvAdjustmentRequest }) => inventoryApi.adjustments.post(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'adjustments'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'adjustments', v.id] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock'] });
      toast.success('Adjustment posted');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to post adjustment'),
  });
}

export function useReverseAdjustment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ReverseInvAdjustmentRequest }) => inventoryApi.adjustments.reverse(id, payload),
    onSuccess: (_, v) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'adjustments'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'adjustments', v.id] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock'] });
      toast.success('Adjustment reversed');
    },
    onError: (e: unknown) => toast.error((e as { response?: { data?: { error?: string } } })?.response?.data?.error || 'Failed to reverse adjustment'),
  });
}

// Stock
export function useStockOnHand(f?: StockOnHandFilters) {
  return useQuery({
    queryKey: ['inventory', 'stock', 'on-hand', f],
    queryFn: () => inventoryApi.stock.onHand(f),
    staleTime: 30 * 1000, // 30 seconds - stock changes but not too frequently
    gcTime: 5 * 60 * 1000,
  });
}

export function useStockMovements(f?: StockMovementsFilters) {
  return useQuery({
    queryKey: ['inventory', 'stock', 'movements', f],
    queryFn: () => inventoryApi.stock.movements(f),
    staleTime: 30 * 1000, // 30 seconds - stock movements
    gcTime: 5 * 60 * 1000,
    placeholderData: keepPreviousData,
  });
}
