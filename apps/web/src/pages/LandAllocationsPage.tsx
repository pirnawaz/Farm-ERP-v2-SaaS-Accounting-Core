import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useLandAllocations, useCreateLandAllocation } from '../hooks/useLandAllocations';
import { useCropCycles } from '../hooks/useCropCycles';
import { useLandParcels } from '../hooks/useLandParcels';
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
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [formData, setFormData] = useState<CreateLandAllocationPayload>({
    crop_cycle_id: '',
    land_parcel_id: '',
    party_id: '',
    allocated_acres: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const canCreate = hasRole(['tenant_admin', 'accountant']);
  const hariParties = parties?.filter((p) => p.party_types.includes('HARI')) || [];

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};
    
    if (!formData.crop_cycle_id) newErrors.crop_cycle_id = 'Crop cycle is required';
    if (!formData.land_parcel_id) newErrors.land_parcel_id = 'Land parcel is required';
    if (!formData.party_id) newErrors.party_id = 'HARI party is required';
    if (!formData.allocated_acres || parseFloat(formData.allocated_acres as string) <= 0) {
      newErrors.allocated_acres = 'Valid allocated acres is required';
    }

    // Check if allocated acres exceed available acres
    if (formData.land_parcel_id && formData.allocated_acres) {
      const parcel = landParcels?.find((p) => p.id === formData.land_parcel_id);
      if (parcel) {
        const existingAllocations = allocations?.filter(
          (a) => a.land_parcel_id === formData.land_parcel_id
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
    if (!validateForm()) return;
    
    try {
      await createMutation.mutateAsync(formData);
      toast.success('Land allocation created successfully');
      setShowCreateModal(false);
      setFormData({ crop_cycle_id: '', land_parcel_id: '', party_id: '', allocated_acres: '' });
      setErrors({});
    } catch (error: any) {
      toast.error(error.message || 'Failed to create land allocation');
    }
  };

  const columns: Column<LandAllocation>[] = [
    {
      header: 'Land Parcel',
      accessor: (row) => row.land_parcel?.name || 'N/A',
    },
    {
      header: 'HARI',
      accessor: (row) => row.party?.name || 'N/A',
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
          <FormField label="HARI Party" required error={errors.party_id}>
            <select
              value={formData.party_id}
              onChange={(e) => {
                setFormData({ ...formData, party_id: e.target.value });
                setErrors({ ...errors, party_id: '' });
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select HARI party</option>
              {hariParties.map((party) => (
                <option key={party.id} value={party.id}>
                  {party.name}
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
          <div className="flex justify-end space-x-3">
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
    </div>
  );
}
