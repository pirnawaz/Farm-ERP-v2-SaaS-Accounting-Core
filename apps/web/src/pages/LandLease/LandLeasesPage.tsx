import { useState, useMemo, useEffect } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import {
  useLandLeases,
  useLandLease,
  useCreateLandLease,
  useUpdateLandLease,
} from '../../hooks/useLandLeases';
import { useProjects } from '../../hooks/useProjects';
import { useLandParcels } from '../../hooks/useLandParcels';
import { useParties } from '../../hooks/useParties';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useRole } from '../../hooks/useRole';
import toast from 'react-hot-toast';
import type { LandLease, CreateLandLeasePayload } from '@farm-erp/shared';

const defaultForm: CreateLandLeasePayload = {
  project_id: '',
  land_parcel_id: '',
  landlord_party_id: '',
  start_date: '',
  end_date: null,
  rent_amount: '',
  frequency: 'MONTHLY',
  notes: '',
};

export default function LandLeasesPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const editIdFromState = (location.state as { editId?: string } | null)?.editId;
  const { data: leases, isLoading } = useLandLeases();
  const { data: leaseToEdit } = useLandLease(editIdFromState ?? '');
  const { data: projects } = useProjects();
  const { data: landParcels } = useLandParcels();
  const { data: parties } = useParties();
  const createMutation = useCreateLandLease();
  const updateMutation = useUpdateLandLease();
  const { hasRole } = useRole();
  const [showModal, setShowModal] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [formData, setFormData] = useState<CreateLandLeasePayload>(defaultForm);
  const canManage = hasRole(['tenant_admin']);

  useEffect(() => {
    if (editIdFromState && leaseToEdit) {
      setEditingId(leaseToEdit.id);
      setFormData({
        project_id: leaseToEdit.project_id,
        land_parcel_id: leaseToEdit.land_parcel_id,
        landlord_party_id: leaseToEdit.landlord_party_id,
        start_date: leaseToEdit.start_date,
        end_date: leaseToEdit.end_date ?? null,
        rent_amount: leaseToEdit.rent_amount,
        frequency: leaseToEdit.frequency,
        notes: leaseToEdit.notes ?? '',
      });
      setShowModal(true);
      navigate(location.pathname, { replace: true, state: {} });
    }
  }, [editIdFromState, leaseToEdit, navigate, location.pathname]);

  const columns: Column<LandLease>[] = useMemo(
    () => [
      {
        header: 'Project',
        accessor: (row) => row.project?.name ?? row.project_id,
      },
      {
        header: 'Land Parcel',
        accessor: (row) => row.land_parcel?.name ?? row.land_parcel_id,
      },
      {
        header: 'Landlord',
        accessor: (row) => row.landlord_party?.name ?? row.landlord_party_id,
      },
      { header: 'Start', accessor: (row) => row.start_date },
      { header: 'End', accessor: (row) => row.end_date ?? '—' },
      { header: 'Rent', accessor: (row) => row.rent_amount },
      {
        header: 'Actions',
        accessor: (row) => (
          <div className="flex space-x-2">
            <Link
              to={`/app/land-leases/${row.id}`}
              className="text-[#1F6F5C] hover:text-[#1a5a4a]"
            >
              View
            </Link>
            {canManage && (
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  setEditingId(row.id);
                  setFormData({
                    project_id: row.project_id,
                    land_parcel_id: row.land_parcel_id,
                    landlord_party_id: row.landlord_party_id,
                    start_date: row.start_date,
                    end_date: row.end_date ?? null,
                    rent_amount: row.rent_amount,
                    frequency: row.frequency,
                    notes: row.notes ?? '',
                  });
                  setShowModal(true);
                }}
                className="text-[#1F6F5C] hover:text-[#1a5a4a]"
              >
                Edit
              </button>
            )}
          </div>
        ),
      },
    ],
    [canManage]
  );

  const openCreate = () => {
    setEditingId(null);
    setFormData(defaultForm);
    setShowModal(true);
  };

  const handleSubmit = async () => {
    const payload = {
      ...formData,
      rent_amount:
        typeof formData.rent_amount === 'string'
          ? parseFloat(formData.rent_amount) || 0
          : formData.rent_amount,
      end_date: formData.end_date || null,
    };
    try {
      if (editingId) {
        await updateMutation.mutateAsync({ id: editingId, payload });
        toast.success('Lease updated successfully');
      } else {
        await createMutation.mutateAsync(payload as CreateLandLeasePayload);
        toast.success('Lease created successfully');
      }
      setShowModal(false);
      setFormData(defaultForm);
      setEditingId(null);
    } catch (error: unknown) {
      const message =
        error && typeof error === 'object' && 'message' in error
          ? String((error as { message: unknown }).message)
          : 'Failed to save lease';
      toast.error(message);
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
    <div data-testid="land-leases-page">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Land Leases (Maqada)</h1>
        {canManage && (
          <button
            data-testid="new-land-lease"
            onClick={openCreate}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Lease
          </button>
        )}
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={leases ?? []}
          columns={columns}
          onRowClick={(row) => navigate(`/app/land-leases/${row.id}`)}
        />
      </div>

      <Modal
        isOpen={showModal}
        onClose={() => {
          setShowModal(false);
          setEditingId(null);
          setFormData(defaultForm);
        }}
        title={editingId ? 'Edit Lease' : 'Create Lease'}
      >
        <div className="space-y-4">
          <FormField label="Project" required>
            <select
              value={formData.project_id}
              onChange={(e) =>
                setFormData({ ...formData, project_id: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select project</option>
              {(projects ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Land Parcel" required>
            <select
              value={formData.land_parcel_id}
              onChange={(e) =>
                setFormData({ ...formData, land_parcel_id: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select parcel</option>
              {(landParcels ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Landlord (Party)" required>
            <select
              value={formData.landlord_party_id}
              onChange={(e) =>
                setFormData({ ...formData, landlord_party_id: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select landlord</option>
              {(parties ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Start date" required>
            <input
              type="date"
              value={formData.start_date}
              onChange={(e) =>
                setFormData({ ...formData, start_date: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="End date">
            <input
              type="date"
              value={formData.end_date ?? ''}
              onChange={(e) =>
                setFormData({
                  ...formData,
                  end_date: e.target.value || null,
                })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Rent amount" required>
            <input
              type="number"
              step="0.01"
              min={0}
              value={formData.rent_amount}
              onChange={(e) =>
                setFormData({ ...formData, rent_amount: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Frequency">
            <select
              value={formData.frequency}
              onChange={(e) =>
                setFormData({
                  ...formData,
                  frequency: e.target.value as 'MONTHLY',
                })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="MONTHLY">Monthly</option>
            </select>
          </FormField>
          <FormField label="Notes">
            <textarea
              value={formData.notes ?? ''}
              onChange={(e) =>
                setFormData({ ...formData, notes: e.target.value || null })
              }
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
              type="button"
              onClick={() => {
                setShowModal(false);
                setEditingId(null);
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleSubmit}
              disabled={
                (editingId ? updateMutation.isPending : createMutation.isPending) ||
                !formData.project_id ||
                !formData.land_parcel_id ||
                !formData.landlord_party_id ||
                !formData.start_date ||
                formData.rent_amount === '' ||
                formData.rent_amount === null
              }
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {editingId
                ? updateMutation.isPending
                  ? 'Saving...'
                  : 'Save'
                : createMutation.isPending
                  ? 'Creating...'
                  : 'Create'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
