import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useLandParcel, useUpdateLandParcel, useLandParcelAudit } from '../hooks/useLandParcels';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { SetupStatusBadge } from '../components/SetupStatusBadge';
import { isSetupComplete } from '../components/setupSemantics';
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
  const agrSum = parcel.agreement_allocation_summary;
  const agreementActive = agrSum?.active_allocated_area ?? 0;
  const agreementAvail = agrSum?.available_area_after_agreement_allocations;

  return (
    <div>
      <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
          <Link to="/app/land" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block text-sm">
            ← Back
          </Link>
          <nav className="text-sm text-gray-500 mb-1 flex flex-wrap gap-x-1">
            <Link to="/app/dashboard" className="text-[#1F6F5C] hover:underline">Farm</Link>
            <span className="text-gray-400">/</span>
            <Link to="/app/land" className="text-[#1F6F5C] hover:underline">Land Parcels</Link>
          </nav>
          <h1 className="text-2xl font-semibold text-gray-900 mt-1">{parcel.name}</h1>
          <p className="mt-1 text-base text-gray-700 max-w-2xl">
            Review acreage, allocations to crop cycles, and remaining acres for this parcel.
          </p>
        </div>
        {canEdit && (
          <button
            type="button"
            onClick={() => setShowEditModal(true)}
            className="self-start sm:self-center px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] text-sm font-medium"
          >
            Edit parcel
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
              <dt className="text-sm font-medium text-gray-500">Remaining Acres (crop-cycle allocations)</dt>
              <dd className="text-sm text-gray-900">
                {formatNumber(remainingAcres, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}
              </dd>
            </div>
            {agrSum && (
              <>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Agreement allocations (active, as of {agrSum.as_of})</dt>
                  <dd className="text-sm text-gray-900">
                    {formatNumber(agreementActive, { minimumFractionDigits: 0, maximumFractionDigits: 4 })} /{' '}
                    {formatNumber(agrSum.parcel_total_area, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}{' '}
                    acres reserved commercially
                  </dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Available after agreement reservations</dt>
                  <dd className="text-sm text-gray-900">
                    {formatNumber(agreementAvail ?? 0, { minimumFractionDigits: 0, maximumFractionDigits: 4 })}
                  </dd>
                </div>
              </>
            )}
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

      {parcel.agreement_allocations && parcel.agreement_allocations.length > 0 && (
        <div className="mt-6 bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Agreement allocations</h2>
          <ul className="space-y-2 text-sm text-gray-800">
            {parcel.agreement_allocations.map((row) => (
              <li key={row.id} className="border border-gray-100 rounded p-3">
                <span className="font-medium">{row.allocated_area} {row.area_uom || 'ACRE'}</span>
                {row.agreement?.agreement_type && (
                  <span className="text-gray-600"> — {row.agreement.agreement_type}</span>
                )}
                <span className="text-gray-600"> — {row.starts_on}</span>
                {row.ends_on ? <span className="text-gray-600"> → {row.ends_on}</span> : <span className="text-gray-600"> (open-ended)</span>}
                <span className={` ml-2 text-xs px-2 py-0.5 rounded ${row.status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'}`}>
                  {row.status}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

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
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span>
                          <span className="tabular-nums font-medium text-gray-900">{alloc.allocated_acres}</span> acres to{' '}
                          {alloc.party?.name || 'Owner-operated'}
                        </span>
                        {alloc.project ? (
                          <Link
                            to={`/app/projects/${alloc.project.id}`}
                            className="text-[#1F6F5C] hover:text-[#1a5a4a] font-medium"
                          >
                            {alloc.project.name}
                          </Link>
                        ) : (
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="text-gray-500">No field cycle</span>
                            <Link
                              to={`/app/projects/setup?allocation_id=${encodeURIComponent(alloc.id)}&crop_cycle_id=${encodeURIComponent(group.crop_cycle.id)}&parcel_id=${encodeURIComponent(parcel.id)}`}
                              className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
                            >
                              Create field cycle
                            </Link>
                          </div>
                        )}
                        {alloc.project && (
                          !isSetupComplete(alloc.project as any) ? (
                            <Link
                              to={`/app/projects/setup?project_id=${encodeURIComponent(alloc.project.id)}&allocation_id=${encodeURIComponent(alloc.id)}&crop_cycle_id=${encodeURIComponent(group.crop_cycle.id)}&parcel_id=${encodeURIComponent(parcel.id)}`}
                              className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
                            >
                              Complete setup
                            </Link>
                          ) : null
                        )}
                        <SetupStatusBadge
                          present={!!(alloc.project?.agreement_allocation_id || alloc.project?.agreement_allocation)}
                          presentLabel="Agreement allocation"
                          missingLabel="No agreement allocation"
                          size="sm"
                        />
                      </div>
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
