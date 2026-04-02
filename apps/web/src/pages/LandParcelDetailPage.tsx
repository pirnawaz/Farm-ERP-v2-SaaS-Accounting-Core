import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useLandParcel, useUpdateLandParcel, useLandParcelAudit } from '../hooks/useLandParcels';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import toast from 'react-hot-toast';
import type { CreateLandParcelPayload } from '../types';

const LAND_DOCUMENTS_ENABLED = import.meta.env.VITE_LAND_DOCUMENTS_ENABLED === 'true';

function getApiErrorMessage(error: unknown): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const res = (error as { response?: { data?: { message?: string; error?: string } } }).response?.data;
    if (res?.message) return res.message;
    if (res?.error) return res.error;
  }
  if (error instanceof Error) return error.message;
  return 'Failed to update land parcel';
}

export default function LandParcelDetailPage() {
  const { id } = useParams<{ id: string }>();
  const parcelId = id || '';
  const { data: parcel, isLoading } = useLandParcel(parcelId);
  const updateMutation = useUpdateLandParcel();
  const { hasRole } = useRole();
  const { formatDateTime, formatNumber } = useFormatting();
  const canEdit = hasRole(['tenant_admin', 'accountant']);
  const { data: auditLogs, isLoading: auditLoading, isError: auditError } = useLandParcelAudit(parcelId, { enabled: canEdit });
  const [showEditModal, setShowEditModal] = useState(false);
  const [formData, setFormData] = useState<CreateLandParcelPayload>({
    name: '',
    total_acres: '',
    notes: '',
  });

  useEffect(() => {
    if (parcel && showEditModal) {
      setFormData({
        name: parcel.name,
        total_acres: parcel.total_acres,
        notes: parcel.notes ?? '',
      });
    }
  }, [parcel, showEditModal]);

  const handleEditSubmit = async () => {
    if (!parcelId) return;
    const totalAcresNum = typeof formData.total_acres === 'string' ? parseFloat(formData.total_acres) : formData.total_acres;
    if (Number.isNaN(totalAcresNum) || totalAcresNum < 0) {
      toast.error('Total acres must be a number greater than or equal to 0');
      return;
    }
    try {
      await updateMutation.mutateAsync({
        id: parcelId,
        payload: {
          name: formData.name.trim(),
          total_acres: totalAcresNum,
          notes: formData.notes?.trim() || undefined,
        },
      });
      toast.success('Land parcel updated');
      setShowEditModal(false);
    } catch (error) {
      toast.error(getApiErrorMessage(error));
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!parcel) {
    return <div>Land parcel not found</div>;
  }

  // Calculate remaining acres
  const totalAllocated = parcel.allocations?.reduce(
    (sum, alloc) => sum + parseFloat(alloc.allocated_acres || '0'),
    0
  ) || 0;
  const remainingAcres = parseFloat(parcel.total_acres) - totalAllocated;

  return (
    <div>
      <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
          <Link to="/app/land" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
            ← Back to Land Parcels
          </Link>
          <h1 className="text-2xl font-bold text-gray-900 mt-2">{parcel.name}</h1>
        </div>
        {canEdit && (
          <button
            type="button"
            onClick={() => setShowEditModal(true)}
            className="self-start sm:self-center px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            Edit Parcel
          </button>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Basic Info */}
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Basic Information</h2>
          <dl className="space-y-2">
            <div>
              <dt className="text-sm font-medium text-gray-500">Total Acres</dt>
              <dd className="text-sm text-gray-900">
                {formatNumber(parseFloat(String(parcel.total_acres)), {
                  minimumFractionDigits: 0,
                  maximumFractionDigits: 2,
                })}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Remaining Acres</dt>
              <dd className="text-sm text-gray-900">
                {formatNumber(remainingAcres, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}
              </dd>
            </div>
            {parcel.notes && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Notes</dt>
                <dd className="text-sm text-gray-900">{parcel.notes}</dd>
              </div>
            )}
          </dl>
        </div>

        {/* Documents: disabled until storage is configured */}
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Documents</h2>
          {LAND_DOCUMENTS_ENABLED ? (
            <p className="text-sm text-gray-500">No documents</p>
          ) : (
            <div
              className="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800"
              role="status"
            >
              Documents are disabled until storage is configured.
            </div>
          )}
        </div>
      </div>

      {/* Allocations by Crop Cycle */}
      <div className="mt-6 bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Allocations by Crop Cycle</h2>
        {(parcel.allocation_summary && parcel.allocation_summary.length > 0) ? (
          <div className="space-y-4">
            {parcel.allocation_summary.map((group, idx) => (
              <div key={idx} className="border rounded p-4">
                <h3 className="font-medium text-gray-900 mb-2">
                  {group.crop_cycle.name} ({group.crop_cycle.crop_display_name ?? group.crop_cycle.crop_type ?? 'N/A'})
                </h3>
                <p className="text-sm text-gray-600 mb-2">
                  Total Allocated: {group.total_allocated_acres} acres
                </p>
                <ul className="space-y-1">
                  {group.allocations.map((alloc) => (
                    <li key={alloc.id} className="text-sm text-gray-700">
                      {alloc.allocated_acres} acres to {alloc.party?.name || 'Owner-operated'} 
                      {alloc.project && (
                        <Link
                          to={`/app/projects/${alloc.project.id}`}
                          className="ml-2 text-[#1F6F5C] hover:text-[#1a5a4a]"
                        >
                          (Project: {alloc.project.name})
                        </Link>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-sm text-gray-500">No allocations</p>
        )}
      </div>

      {canEdit && (
        <div className="mt-6 bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Audit</h2>
          {auditLoading ? (
            <p className="text-sm text-gray-500">Loading…</p>
          ) : auditError || !auditLogs ? (
            <p className="text-sm text-gray-500">No changes recorded.</p>
          ) : auditLogs.length === 0 ? (
            <p className="text-sm text-gray-500">No changes recorded.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                  <tr>
                    <th className="px-3 py-2 text-left font-medium text-gray-700">Date</th>
                    <th className="px-3 py-2 text-left font-medium text-gray-700">User / Role</th>
                    <th className="px-3 py-2 text-left font-medium text-gray-700">Field</th>
                    <th className="px-3 py-2 text-left font-medium text-gray-700">Old → New</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {auditLogs.map((log) => (
                    <tr key={log.id}>
                      <td className="px-3 py-2 text-gray-600">
                        {log.changed_at ? formatDateTime(log.changed_at) : '—'}
                      </td>
                      <td className="px-3 py-2 text-gray-600">
                        {log.changed_by_user_id || '—'} / {log.changed_by_role || '—'}
                      </td>
                      <td className="px-3 py-2 text-gray-900">{log.field_name}</td>
                      <td className="px-3 py-2 text-gray-600">
                        {log.old_value ?? '—'} → {log.new_value ?? '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      <Modal
        isOpen={showEditModal}
        onClose={() => setShowEditModal(false)}
        title="Edit Parcel"
      >
        <div className="space-y-4">
          <FormField label="Name" required>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Total Acres" required>
            <input
              type="number"
              step="0.01"
              min={0}
              value={formData.total_acres}
              onChange={(e) => setFormData({ ...formData, total_acres: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Notes">
            <textarea
              value={formData.notes ?? ''}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-2">
            <button
              type="button"
              onClick={() => setShowEditModal(false)}
              className="w-full sm:w-auto px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleEditSubmit}
              disabled={updateMutation.isPending || !formData.name.trim() || formData.total_acres === ''}
              className="w-full sm:w-auto px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {updateMutation.isPending ? 'Saving...' : 'Save'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
