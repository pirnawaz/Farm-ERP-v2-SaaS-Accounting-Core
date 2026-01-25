import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useLandParcels, useCreateLandParcel } from '../hooks/useLandParcels';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import toast from 'react-hot-toast';
import type { LandParcel, CreateLandParcelPayload } from '../types';

export default function LandParcelsPage() {
  const navigate = useNavigate();
  const { data: parcels, isLoading } = useLandParcels();
  const createMutation = useCreateLandParcel();
  const { hasRole } = useRole();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [formData, setFormData] = useState<CreateLandParcelPayload>({
    name: '',
    total_acres: '',
    notes: '',
  });

  const canCreate = hasRole(['tenant_admin', 'accountant']);

  const columns: Column<LandParcel>[] = [
    { header: 'Name', accessor: 'name' },
    { header: 'Total Acres', accessor: 'total_acres' },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex space-x-2">
          <Link
            to={`/app/land/${row.id}`}
            className="text-blue-600 hover:text-blue-900"
          >
            View
          </Link>
          {canCreate && (
            <Link
              to={`/app/land/${row.id}/edit`}
              className="text-blue-600 hover:text-blue-900"
            >
              Edit
            </Link>
          )}
        </div>
      ),
    },
  ];

  const handleCreate = async () => {
    try {
      await createMutation.mutateAsync(formData);
      toast.success('Land parcel created successfully');
      setShowCreateModal(false);
      setFormData({ name: '', total_acres: '', notes: '' });
    } catch (error: any) {
      toast.error(error.message || 'Failed to create land parcel');
    }
  };

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
        <h1 className="text-2xl font-bold text-gray-900">Land Parcels</h1>
        {canCreate && (
          <button
            onClick={() => setShowCreateModal(true)}
            className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
          >
            New Land Parcel
          </button>
        )}
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={parcels || []}
          columns={columns}
          onRowClick={(row) => navigate(`/app/land/${row.id}`)}
        />
      </div>

      <Modal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title="Create Land Parcel"
      >
        <div className="space-y-4">
          <FormField label="Name" required>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </FormField>
          <FormField label="Total Acres" required>
            <input
              type="number"
              step="0.01"
              value={formData.total_acres}
              onChange={(e) => setFormData({ ...formData, total_acres: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </FormField>
          <FormField label="Notes">
            <textarea
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
              onClick={() => setShowCreateModal(false)}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handleCreate}
              disabled={createMutation.isPending || !formData.name || !formData.total_acres}
              className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createMutation.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
