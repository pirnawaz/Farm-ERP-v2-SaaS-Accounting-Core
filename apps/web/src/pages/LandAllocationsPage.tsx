import { useState } from 'react';
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
import toast from 'react-hot-toast';
import type { LandAllocation, CreateLandAllocationPayload } from '../types';

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
      header: 'Land Parcel',
      accessor: (row) => row.land_parcel?.name || 'N/A',
    },
    {
      header: 'HARI',
      accessor: (row) => row.party?.name || 'Owner-operated',
    },
    { header: 'Allocated Acres', accessor: 'allocated_acres' },
    {
      header: 'Project',
      accessor: (row) =>
        row.project ? (
          <Link
            to={`/app/projects/${row.project.id}`}
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            {row.project.name}
          </Link>
        ) : (
          'No project'
        ),
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

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Land Allocations</h1>
        {canCreate && (
          <button
            data-testid="new-land-allocation"
            onClick={() => setShowCreateModal(true)}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Allocation
          </button>
        )}
      </div>

      <div className="mb-4">
        <label className="block text-sm font-medium text-gray-700 mb-2">
          Filter by Crop Cycle
        </label>
        <select
          value={selectedCropCycleId}
          onChange={(e) => setSelectedCropCycleId(e.target.value)}
          className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
        >
          <option value="">All Crop Cycles</option>
          {cropCycles?.map((cycle) => (
            <option key={cycle.id} value={cycle.id}>
              {cycle.name}
            </option>
          ))}
        </select>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={allocations || []}
          columns={columns}
          emptyMessage="No allocations found"
        />
      </div>

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
