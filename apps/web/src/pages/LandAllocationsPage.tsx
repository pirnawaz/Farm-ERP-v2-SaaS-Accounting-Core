import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useLandAllocations, useCreateLandAllocation, useUpdateLandAllocation, useDeleteLandAllocation } from '../hooks/useLandAllocations';
import { useCropCycles } from '../hooks/useCropCycles';
import { useLandParcels, useRotationWarnings } from '../hooks/useLandParcels';
import { useParties } from '../hooks/useParties';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import { SetupStatusBadge } from '../components/SetupStatusBadge';
import { getSetupCompleteness } from '../components/setupSemantics';
import toast from 'react-hot-toast';
import type { LandAllocation, CreateLandAllocationPayload } from '../types';

function projectSetupIncomplete(p?: { land_allocation_id?: string | null; field_block_id?: string | null; agreement_id?: string | null; agreement_allocation_id?: string | null } | null): boolean {
  if (!p) return false;
  return getSetupCompleteness(p as any) !== 'COMPLETE';
}

export default function LandAllocationsPage() {
  const { hasRole } = useRole();
  const [selectedCropCycleId, setSelectedCropCycleId] = useState<string>('');
  const { data: cropCycles } = useCropCycles();
  const { data: allocations, isLoading } = useLandAllocations(selectedCropCycleId || undefined);
  const { data: landParcels } = useLandParcels();
  const { data: parties } = useParties();
  const createMutation = useCreateLandAllocation();
  const updateMutation = useUpdateLandAllocation();
  const deleteMutation = useDeleteLandAllocation();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingAllocation, setEditingAllocation] = useState<LandAllocation | null>(null);
  const [allocationToDelete, setAllocationToDelete] = useState<LandAllocation | null>(null);
  const [formData, setFormData] = useState<CreateLandAllocationPayload>({
    crop_cycle_id: '',
    land_parcel_id: '',
    party_id: null,
    allocated_acres: '',
    allocation_mode: 'OWNER',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const canCreate = hasRole(['tenant_admin', 'accountant']);
  const hariParties = parties?.filter((p) => p.party_types?.includes('HARI')) ?? [];
  const hasHariParties = hariParties.length > 0;

  const { data: rotationData } = useRotationWarnings(
    formData.land_parcel_id || '',
    formData.crop_cycle_id || ''
  );
  const rotationWarnings = rotationData?.warnings ?? [];

  const validateForm = (isEdit: boolean): boolean => {
    const newErrors: Record<string, string> = {};
    if (!isEdit) {
      if (!formData.crop_cycle_id) newErrors.crop_cycle_id = 'Crop cycle is required';
      if (!formData.land_parcel_id) newErrors.land_parcel_id = 'Land parcel is required';
    }
    if (!formData.allocated_acres || parseFloat(formData.allocated_acres as string) <= 0) {
      newErrors.allocated_acres = 'Valid allocated acres is required';
    }
    if (!isEdit && formData.land_parcel_id && formData.allocated_acres) {
      const parcel = landParcels?.find((p) => p.id === formData.land_parcel_id);
      if (parcel) {
        const existingAllocations = allocations?.filter(
          (a) => a.land_parcel_id === formData.land_parcel_id && a.id !== editingAllocation?.id
        ) || [];
        const totalAllocated = existingAllocations.reduce(
          (sum, a) => sum + parseFloat(a.allocated_acres || '0'),
          0
        );
        const requested = parseFloat(formData.allocated_acres as string);
        const available = parseFloat(parcel.total_acres) - totalAllocated;
        if (requested > available) {
          newErrors.allocated_acres = `Only ${available.toFixed(2)} acres available`;
        }
      }
    }
    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleCreate = async () => {
    if (!validateForm(false)) return;
    try {
      const payload: CreateLandAllocationPayload = {
        ...formData,
        party_id: formData.party_id || null,
      };
      await createMutation.mutateAsync(payload);
      toast.success('Land allocation created successfully');
      setShowCreateModal(false);
      setFormData({ crop_cycle_id: '', land_parcel_id: '', party_id: null, allocated_acres: '', allocation_mode: 'OWNER' });
      setErrors({});
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? 'Failed to create land allocation');
    }
  };

  const handleEdit = (row: LandAllocation) => {
    setEditingAllocation(row);
    setFormData({
      crop_cycle_id: row.crop_cycle_id,
      land_parcel_id: row.land_parcel_id,
      party_id: row.party_id ?? null,
      allocated_acres: row.allocated_acres ?? '',
      allocation_mode: (row.party_id ? 'HARI' : 'OWNER') as 'OWNER' | 'HARI',
    });
    setErrors({});
  };

  const handleUpdate = async () => {
    if (!editingAllocation || !validateForm(true)) return;
    try {
      await updateMutation.mutateAsync({
        id: editingAllocation.id,
        payload: {
          allocated_acres: Number(formData.allocated_acres),
          party_id: formData.party_id || null,
        },
      });
      toast.success('Land allocation updated successfully');
      setEditingAllocation(null);
      setErrors({});
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? 'Failed to update land allocation');
    }
  };

  const handleDeleteConfirm = async () => {
    if (!allocationToDelete) return;
    try {
      await deleteMutation.mutateAsync(allocationToDelete.id);
      toast.success('Land allocation deleted');
      setAllocationToDelete(null);
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? 'Failed to delete land allocation');
    }
  };

  const columns: Column<LandAllocation>[] = [
    {
      header: 'Land parcel',
      accessor: (row) => (
        row.land_parcel?.id ? (
          <Link
            to={`/app/land/${row.land_parcel.id}`}
            className="font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            {row.land_parcel?.name || '—'}
          </Link>
        ) : (
          <span className="font-medium text-gray-900">{row.land_parcel?.name || '—'}</span>
        )
      ),
    },
    {
      header: 'Crop cycle',
      accessor: (row) => <span className="text-gray-800">{row.crop_cycle?.name ?? '—'}</span>,
    },
    {
      header: 'Assignee',
      accessor: (row) => (
        <span className="text-gray-800">{row.party?.name || 'Owner-operated'}</span>
      ),
    },
    {
      header: 'Allocated acres',
      accessor: (row) => (
        <span className="tabular-nums text-gray-900 text-right block">{row.allocated_acres}</span>
      ),
      numeric: true,
      align: 'right',
    },
    {
      header: 'Field cycle',
      accessor: (row) =>
        row.project ? (
          <div className="flex flex-wrap items-center gap-2">
            <Link
              to={`/app/projects/${row.project.id}`}
              className="font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
            >
              {row.project.name}
            </Link>
            {projectSetupIncomplete(row.project) && (
              <Link
                to={`/app/projects/setup?project_id=${encodeURIComponent(row.project.id)}&allocation_id=${encodeURIComponent(row.id)}&crop_cycle_id=${encodeURIComponent(row.crop_cycle_id)}&parcel_id=${encodeURIComponent(row.land_parcel_id)}`}
                className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
              >
                Complete setup
              </Link>
            )}
          </div>
        ) : (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-gray-500">Not created</span>
            <Link
              to={`/app/projects/setup?allocation_id=${encodeURIComponent(row.id)}&crop_cycle_id=${encodeURIComponent(row.crop_cycle_id)}&parcel_id=${encodeURIComponent(row.land_parcel_id)}`}
              className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
            >
              Create field cycle
            </Link>
          </div>
        ),
    },
    {
      header: 'Agreement allocation',
      accessor: (row) => {
        const hasAgreementAllocation = !!(row.project?.agreement_allocation_id || row.project?.agreement_allocation);
        return (
          <SetupStatusBadge
            present={hasAgreementAllocation}
            presentLabel="Present"
            missingLabel="Missing"
            size="sm"
          />
        );
      },
    },
    ...(canCreate
      ? [
          {
            header: 'Actions',
            accessor: (row: LandAllocation) => (
              <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                <button
                  type="button"
                  onClick={() => handleEdit(row)}
                  className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  Edit
                </button>
                <button
                  type="button"
                  onClick={() => setAllocationToDelete(row)}
                  className="text-sm font-medium text-red-600 hover:text-red-700"
                >
                  Delete
                </button>
              </div>
            ),
          } as Column<LandAllocation>,
        ]
      : []),
  ];

  const allocationRows = allocations ?? [];
  const hasFilter = selectedCropCycleId.length > 0;
  const selectedCycleName = useMemo(
    () => cropCycles?.find((c) => c.id === selectedCropCycleId)?.name,
    [cropCycles, selectedCropCycleId],
  );
  const summaryLine = useMemo(() => {
    const n = allocationRows.length;
    const base = `${n} ${n === 1 ? 'allocation' : 'allocations'}`;
    if (hasFilter && selectedCycleName) return `${base} · Crop cycle: ${selectedCycleName}`;
    return base;
  }, [allocationRows.length, hasFilter, selectedCycleName]);

  const clearFilters = () => setSelectedCropCycleId('');

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  const showEmpty = allocationRows.length === 0;

  return (
    <div data-testid="land-allocations-page" className="space-y-6 max-w-7xl">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Land Allocation</h1>
          <p className="mt-1 text-base text-gray-700">Track how land parcels are allocated for use.</p>
          <p className="mt-1 text-sm text-gray-500 max-w-2xl">
            Use land allocation to assign parcel area into crop and field planning.
          </p>
        </div>
        {canCreate && (
          <button
            data-testid="new-land-allocation"
            type="button"
            onClick={() => setShowCreateModal(true)}
            className="shrink-0 px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Allocation
          </button>
        )}
      </div>

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasFilter}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="max-w-md">
          <label htmlFor="allocation-crop-cycle" className="block text-xs font-medium text-gray-600 mb-1">
            Crop cycle
          </label>
          <select
            id="allocation-crop-cycle"
            value={selectedCropCycleId}
            onChange={(e) => setSelectedCropCycleId(e.target.value)}
            className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          >
            <option value="">All crop cycles</option>
            {cropCycles?.map((cycle) => (
              <option key={cycle.id} value={cycle.id}>
                {cycle.name}
              </option>
            ))}
          </select>
          <p className="mt-1.5 text-xs text-gray-500">Show allocations for one season, or all seasons.</p>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {showEmpty ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">
            {hasFilter ? 'No allocations for this crop cycle.' : 'No land allocations yet.'}
          </h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            {hasFilter
              ? 'Try another crop cycle or clear filters to see all allocations.'
              : 'Add one to assign land into use.'}
          </p>
          {hasFilter ? (
            <button
              type="button"
              onClick={clearFilters}
              className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
            >
              Clear filters
            </button>
          ) : canCreate ? (
            <button
              type="button"
              onClick={() => setShowCreateModal(true)}
              className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              New Allocation
            </button>
          ) : null}
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable data={allocationRows} columns={columns} emptyMessage="" />
        </div>
      )}

      <Modal
        isOpen={showCreateModal}
        onClose={() => {
          setShowCreateModal(false);
          setErrors({});
        }}
        title="Create Land Allocation"
        size="lg"
      >
        <div className="space-y-4">
          <FormField label="Crop Cycle" required error={errors.crop_cycle_id}>
            <select
              value={formData.crop_cycle_id}
              onChange={(e) => {
                setFormData({ ...formData, crop_cycle_id: e.target.value });
                setErrors({ ...errors, crop_cycle_id: '' });
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select crop cycle</option>
              {cropCycles?.map((cycle) => (
                <option key={cycle.id} value={cycle.id}>
                  {cycle.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Land Parcel" required error={errors.land_parcel_id}>
            <select
              value={formData.land_parcel_id}
              onChange={(e) => {
                setFormData({ ...formData, land_parcel_id: e.target.value });
                setErrors({ ...errors, land_parcel_id: '' });
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select land parcel</option>
              {landParcels?.map((parcel) => (
                <option key={parcel.id} value={parcel.id}>
                  {parcel.name} ({parcel.total_acres} acres)
                </option>
              ))}
            </select>
          </FormField>
          {formData.land_parcel_id && formData.crop_cycle_id && rotationWarnings.length > 0 && (
            <div className="rounded-md bg-amber-50 border border-amber-200 p-3" role="alert">
              <p className="text-sm font-medium text-amber-800 mb-1">Crop rotation</p>
              <ul className="list-disc list-inside text-sm text-amber-700 space-y-0.5">
                {rotationWarnings.map((w, i) => (
                  <li key={i}>{w.message}</li>
                ))}
              </ul>
            </div>
          )}
          <FormField label="Hari" error={errors.party_id}>
            <select
              value={formData.party_id ?? ''}
              onChange={(e) => {
                setFormData({ ...formData, party_id: e.target.value || null });
                setErrors({ ...errors, party_id: '' });
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              data-testid="create-allocation-hari-select"
            >
              <option value="">Owner-operated</option>
              {hariParties.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
            {!hasHariParties && (
              <p className="mt-1 text-sm text-gray-500">
                No Hari parties found. Add one in{' '}
                <Link to="/app/parties" className="text-[#1F6F5C] hover:underline">
                  Parties
                </Link>
                .
              </p>
            )}
          </FormField>
          <FormField label="Allocated Acres" required error={errors.allocated_acres}>
            <input
              type="number"
              step="0.01"
              value={formData.allocated_acres}
              onChange={(e) => {
                setFormData({ ...formData, allocated_acres: e.target.value });
                setErrors({ ...errors, allocated_acres: '' });
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
            <button
              onClick={() => {
                setShowCreateModal(false);
                setErrors({});
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handleCreate}
              disabled={createMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createMutation.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={!!editingAllocation}
        onClose={() => {
          setEditingAllocation(null);
          setErrors({});
        }}
        title="Edit Land Allocation"
        size="lg"
      >
        <div className="space-y-4">
          <FormField label="Crop Cycle">
            <input
              type="text"
              readOnly
              value={editingAllocation?.crop_cycle?.name ?? ''}
              className="w-full px-3 py-2 border border-gray-200 rounded-md bg-gray-50 text-gray-600"
            />
          </FormField>
          <FormField label="Land Parcel">
            <input
              type="text"
              readOnly
              value={editingAllocation?.land_parcel?.name ?? ''}
              className="w-full px-3 py-2 border border-gray-200 rounded-md bg-gray-50 text-gray-600"
            />
          </FormField>
          <FormField label="Hari" error={errors.party_id}>
            <select
              value={formData.party_id ?? ''}
              onChange={(e) => {
                setFormData({ ...formData, party_id: e.target.value || null });
                setErrors({ ...errors, party_id: '' });
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              data-testid="edit-allocation-hari-select"
            >
              <option value="">Owner-operated</option>
              {hariParties.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Allocated Acres" required error={errors.allocated_acres}>
            <input
              type="number"
              step="0.01"
              value={formData.allocated_acres}
              onChange={(e) => {
                setFormData({ ...formData, allocated_acres: e.target.value });
                setErrors({ ...errors, allocated_acres: '' });
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
            <button
              onClick={() => {
                setEditingAllocation(null);
                setErrors({});
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handleUpdate}
              disabled={updateMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {updateMutation.isPending ? 'Saving...' : 'Save'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={!!allocationToDelete}
        onClose={() => setAllocationToDelete(null)}
        title="Delete Land Allocation"
      >
        <p className="text-gray-600 mb-4">
          Are you sure you want to delete this allocation? This cannot be undone.
        </p>
        <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
          <button
            onClick={() => setAllocationToDelete(null)}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            onClick={handleDeleteConfirm}
            disabled={deleteMutation.isPending}
            className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {deleteMutation.isPending ? 'Deleting...' : 'Delete'}
          </button>
        </div>
      </Modal>
    </div>
  );
}
