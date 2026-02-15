import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useLandLease, useDeleteLandLease } from '../../hooks/useLandLeases';
import {
  useLandLeaseAccruals,
  useCreateLandLeaseAccrual,
  useUpdateLandLeaseAccrual,
  useDeleteLandLeaseAccrual,
  usePostLandLeaseAccrual,
  useReverseLandLeaseAccrual,
} from '../../hooks/useLandLeaseAccruals';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useRole } from '../../hooks/useRole';
import toast from 'react-hot-toast';
import type {
  LandLeaseAccrual,
  CreateLandLeaseAccrualPayload,
} from '@farm-erp/shared';

const defaultAccrualForm = {
  period_start: '',
  period_end: '',
  amount: '',
  memo: '',
};

export default function LandLeaseDetailPage() {
  const { id: leaseId } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: lease, isLoading } = useLandLease(leaseId ?? '');
  const { data: accrualsResponse, isLoading: accrualsLoading } =
    useLandLeaseAccruals(leaseId);
  const deleteLeaseMutation = useDeleteLandLease();
  const createAccrualMutation = useCreateLandLeaseAccrual();
  const updateAccrualMutation = useUpdateLandLeaseAccrual();
  const deleteAccrualMutation = useDeleteLandLeaseAccrual();
  const postAccrualMutation = usePostLandLeaseAccrual();
  const reverseAccrualMutation = useReverseLandLeaseAccrual();
  const { hasRole } = useRole();
  const canManage = hasRole(['tenant_admin']);

  const [showAccrualModal, setShowAccrualModal] = useState(false);
  const [editingAccrual, setEditingAccrual] = useState<LandLeaseAccrual | null>(
    null
  );
  const [accrualForm, setAccrualForm] = useState(defaultAccrualForm);
  const [showPostModal, setShowPostModal] = useState(false);
  const [accrualToPost, setAccrualToPost] = useState<LandLeaseAccrual | null>(null);
  const [postingDate, setPostingDate] = useState('');
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [accrualToReverse, setAccrualToReverse] = useState<LandLeaseAccrual | null>(null);
  const [reversePostingDate, setReversePostingDate] = useState('');
  const [reverseReason, setReverseReason] = useState('');

  const accruals = accrualsResponse?.data ?? [];

  const handleDeleteLease = async () => {
    if (!leaseId || !window.confirm('Delete this lease? This cannot be undone.'))
      return;
    try {
      await deleteLeaseMutation.mutateAsync(leaseId);
      toast.success('Lease deleted');
      navigate('/app/land-leases');
    } catch (error: unknown) {
      const message =
        error && typeof error === 'object' && 'message' in error
          ? String((error as { message: unknown }).message)
          : 'Failed to delete lease';
      toast.error(message);
    }
  };

  const openNewAccrual = () => {
    setEditingAccrual(null);
    setAccrualForm(defaultAccrualForm);
    setShowAccrualModal(true);
  };

  const openEditAccrual = (accrual: LandLeaseAccrual) => {
    if (accrual.status !== 'DRAFT') return;
    setEditingAccrual(accrual);
    setAccrualForm({
      period_start: accrual.period_start,
      period_end: accrual.period_end,
      amount: accrual.amount,
      memo: accrual.memo ?? '',
    });
    setShowAccrualModal(true);
  };

  const handleSaveAccrual = async () => {
    if (!lease || !leaseId) return;
    const payload: CreateLandLeaseAccrualPayload = {
      lease_id: leaseId,
      project_id: lease.project_id,
      period_start: accrualForm.period_start,
      period_end: accrualForm.period_end,
      amount: accrualForm.amount === '' ? 0 : parseFloat(accrualForm.amount),
      memo: accrualForm.memo || null,
    };
    try {
      if (editingAccrual) {
        await updateAccrualMutation.mutateAsync({
          id: editingAccrual.id,
          payload: {
            period_start: payload.period_start,
            period_end: payload.period_end,
            amount: payload.amount,
            memo: payload.memo,
          },
          leaseId,
        });
        toast.success('Accrual updated');
      } else {
        await createAccrualMutation.mutateAsync(payload);
        toast.success('Accrual created');
      }
      setShowAccrualModal(false);
      setEditingAccrual(null);
      setAccrualForm(defaultAccrualForm);
    } catch (error: unknown) {
      const message =
        error && typeof error === 'object' && 'message' in error
          ? String((error as { message: unknown }).message)
          : 'Failed to save accrual';
      toast.error(message);
    }
  };

  const handleDeleteAccrual = async (accrual: LandLeaseAccrual) => {
    if (accrual.status !== 'DRAFT') return;
    if (!window.confirm('Delete this accrual?')) return;
    try {
      await deleteAccrualMutation.mutateAsync({ id: accrual.id, leaseId: leaseId! });
      toast.success('Accrual deleted');
    } catch (error: unknown) {
      const message =
        error && typeof error === 'object' && 'message' in error
          ? String((error as { message: unknown }).message)
          : 'Failed to delete accrual';
      toast.error(message);
    }
  };

  const openPostModal = (accrual: LandLeaseAccrual) => {
    if (accrual.status !== 'DRAFT') return;
    setAccrualToPost(accrual);
    const defaultDate =
      accrual.period_end ||
      new Date().toISOString().slice(0, 10);
    setPostingDate(defaultDate);
    setShowPostModal(true);
  };

  const handlePostAccrual = async () => {
    if (!accrualToPost || !leaseId || !postingDate) return;
    try {
      await postAccrualMutation.mutateAsync({
        id: accrualToPost.id,
        payload: { posting_date: postingDate },
        leaseId,
      });
      toast.success('Accrual posted');
      setShowPostModal(false);
      setAccrualToPost(null);
      setPostingDate('');
    } catch (error: unknown) {
      const message =
        error && typeof error === 'object' && 'message' in error
          ? String((error as { message: unknown }).message)
          : 'Failed to post accrual';
      toast.error(message);
    }
  };

  const openReverseModal = (accrual: LandLeaseAccrual) => {
    if (accrual.status !== 'POSTED' || accrual.reversal_posting_group_id) return;
    setAccrualToReverse(accrual);
    setReversePostingDate(new Date().toISOString().slice(0, 10));
    setReverseReason('');
    setShowReverseModal(true);
  };

  const handleReverseAccrual = async () => {
    if (!accrualToReverse || !leaseId || !reversePostingDate) return;
    try {
      await reverseAccrualMutation.mutateAsync({
        id: accrualToReverse.id,
        payload: { posting_date: reversePostingDate, reason: reverseReason || undefined },
        leaseId,
      });
      toast.success('Accrual reversed');
      setShowReverseModal(false);
      setAccrualToReverse(null);
      setReversePostingDate('');
      setReverseReason('');
    } catch (error: unknown) {
      const message =
        error && typeof error === 'object' && 'message' in error
          ? String((error as { message: unknown }).message)
          : 'Failed to reverse accrual';
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

  if (!lease) {
    return (
      <div>
        <Link
          to="/app/land-leases"
          className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block"
        >
          ← Back to Land Leases
        </Link>
        <p className="text-gray-600">Lease not found.</p>
      </div>
    );
  }

  const projectName =
    lease.project && 'name' in lease.project ? lease.project.name : lease.project_id;
  const parcelName =
    lease.land_parcel && 'name' in lease.land_parcel
      ? lease.land_parcel.name
      : lease.land_parcel_id;
  const landlordName =
    lease.landlord_party && 'name' in lease.landlord_party
      ? lease.landlord_party.name
      : lease.landlord_party_id;

  const isAccrualSaving =
    createAccrualMutation.isPending || updateAccrualMutation.isPending;
  const accrualFormValid =
    accrualForm.period_start &&
    accrualForm.period_end &&
    accrualForm.amount !== '' &&
    parseFloat(accrualForm.amount) >= 0 &&
    accrualForm.period_start <= accrualForm.period_end;

  return (
    <div data-testid="land-lease-detail-page">
      <div className="mb-6">
        <Link
          to="/app/land-leases"
          className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block"
        >
          ← Back to Land Leases
        </Link>
        <div className="flex justify-between items-start mt-2">
          <h1 className="text-2xl font-bold text-gray-900">
            Lease: {projectName} / {parcelName}
          </h1>
          {canManage && (
            <div className="flex space-x-2">
              <Link
                to="/app/land-leases"
                state={{ editId: lease.id } as { editId: string }}
                className="px-3 py-1.5 text-sm font-medium text-[#1F6F5C] border border-[#1F6F5C] rounded-md hover:bg-[#1F6F5C] hover:text-white"
              >
                Edit
              </Link>
              <button
                type="button"
                onClick={handleDeleteLease}
                disabled={deleteLeaseMutation.isPending}
                className="px-3 py-1.5 text-sm font-medium text-red-700 border border-red-300 rounded-md hover:bg-red-50 disabled:opacity-50"
              >
                {deleteLeaseMutation.isPending ? 'Deleting...' : 'Delete'}
              </button>
            </div>
          )}
        </div>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">
          Basic information
        </h2>
        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Project</dt>
            <dd className="text-sm text-gray-900">{projectName}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Land parcel</dt>
            <dd className="text-sm text-gray-900">{parcelName}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Landlord</dt>
            <dd className="text-sm text-gray-900">{landlordName}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Start date</dt>
            <dd className="text-sm text-gray-900">{lease.start_date}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">End date</dt>
            <dd className="text-sm text-gray-900">
              {lease.end_date ?? '—'}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Rent amount</dt>
            <dd className="text-sm text-gray-900">{lease.rent_amount}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Frequency</dt>
            <dd className="text-sm text-gray-900">{lease.frequency}</dd>
          </div>
        </dl>
        {lease.notes && (
          <div className="mt-4">
            <dt className="text-sm font-medium text-gray-500">Notes</dt>
            <dd className="text-sm text-gray-900 mt-1">{lease.notes}</dd>
          </div>
        )}
      </div>

      {/* Accruals section */}
      <div className="mt-8 bg-white rounded-lg shadow p-6">
        <div className="flex justify-between items-center mb-4">
          <h2 className="text-lg font-medium text-gray-900">Accruals</h2>
          {canManage && (
            <button
              type="button"
              data-testid="new-accrual"
              onClick={openNewAccrual}
              className="px-3 py-1.5 text-sm font-medium text-[#1F6F5C] border border-[#1F6F5C] rounded-md hover:bg-[#1F6F5C] hover:text-white"
            >
              New Accrual
            </button>
          )}
        </div>
        {accrualsLoading ? (
          <div className="flex justify-center py-8">
            <LoadingSpinner size="md" />
          </div>
        ) : accruals.length === 0 ? (
          <p className="text-sm text-gray-500">No accruals yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead>
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Period start
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Period end
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">
                    Amount
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Memo
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Status
                  </th>
                  {canManage && (
                    <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">
                      Actions
                    </th>
                  )}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {accruals.map((accrual) => (
                  <tr key={accrual.id}>
                    <td className="px-4 py-2 text-sm text-gray-900">
                      {accrual.period_start}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-900">
                      {accrual.period_end}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-900 text-right">
                      {accrual.amount}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-600 max-w-xs truncate">
                      {accrual.memo ?? '—'}
                    </td>
                    <td className="px-4 py-2">
                      <span
                        className={`inline-flex px-2 py-0.5 text-xs font-medium rounded-full ${
                          accrual.status === 'POSTED'
                            ? 'bg-green-100 text-green-800'
                            : 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {accrual.status}
                      </span>
                      {accrual.status === 'POSTED' && accrual.reversal_posting_group_id && (
                        <span className="ml-2 inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800">
                          Reversed
                        </span>
                      )}
                    </td>
                    {canManage && (
                      <td className="px-4 py-2 text-right text-sm">
                        {accrual.status === 'DRAFT' ? (
                          <>
                            <button
                              type="button"
                              onClick={() => openPostModal(accrual)}
                              disabled={postAccrualMutation.isPending}
                              className="text-[#1F6F5C] hover:text-[#1a5a4a] mr-3"
                            >
                              Post
                            </button>
                            <button
                              type="button"
                              onClick={() => openEditAccrual(accrual)}
                              className="text-[#1F6F5C] hover:text-[#1a5a4a] mr-3"
                            >
                              Edit
                            </button>
                            <button
                              type="button"
                              onClick={() => handleDeleteAccrual(accrual)}
                              disabled={deleteAccrualMutation.isPending}
                              className="text-red-600 hover:text-red-800"
                            >
                              Delete
                            </button>
                          </>
                        ) : accrual.status === 'POSTED' ? (
                          <>
                            {accrual.posting_group_id && (
                              <Link
                                to={`/app/posting-groups/${accrual.posting_group_id}`}
                                className="text-[#1F6F5C] hover:text-[#1a5a4a] mr-3"
                              >
                                View Posting
                              </Link>
                            )}
                            {accrual.reversal_posting_group_id ? (
                              <Link
                                to={`/app/posting-groups/${accrual.reversal_posting_group_id}`}
                                className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                              >
                                View Reversal
                              </Link>
                            ) : (
                              <button
                                type="button"
                                onClick={() => openReverseModal(accrual)}
                                disabled={reverseAccrualMutation.isPending}
                                className="text-amber-600 hover:text-amber-800"
                              >
                                Reverse
                              </button>
                            )}
                          </>
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal
        isOpen={showAccrualModal}
        onClose={() => {
          setShowAccrualModal(false);
          setEditingAccrual(null);
          setAccrualForm(defaultAccrualForm);
        }}
        title={editingAccrual ? 'Edit Accrual' : 'New Accrual'}
      >
        <div className="space-y-4">
          <FormField label="Period start" required>
            <input
              type="date"
              value={accrualForm.period_start}
              onChange={(e) =>
                setAccrualForm({ ...accrualForm, period_start: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Period end" required>
            <input
              type="date"
              value={accrualForm.period_end}
              onChange={(e) =>
                setAccrualForm({ ...accrualForm, period_end: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Amount" required>
            <input
              type="number"
              step="0.01"
              min={0}
              value={accrualForm.amount}
              onChange={(e) =>
                setAccrualForm({ ...accrualForm, amount: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Memo">
            <textarea
              value={accrualForm.memo}
              onChange={(e) =>
                setAccrualForm({ ...accrualForm, memo: e.target.value })
              }
              rows={2}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
              type="button"
              onClick={() => {
                setShowAccrualModal(false);
                setEditingAccrual(null);
                setAccrualForm(defaultAccrualForm);
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleSaveAccrual}
              disabled={!accrualFormValid || isAccrualSaving}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isAccrualSaving ? 'Saving...' : editingAccrual ? 'Save' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showPostModal}
        onClose={() => {
          setShowPostModal(false);
          setAccrualToPost(null);
          setPostingDate('');
        }}
        title="Post Accrual"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            Posting will create accounting entries (expense and landlord payable). This cannot be undone.
          </p>
          <FormField label="Posting date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
              type="button"
              onClick={() => {
                setShowPostModal(false);
                setAccrualToPost(null);
                setPostingDate('');
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handlePostAccrual}
              disabled={!postingDate || postAccrualMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {postAccrualMutation.isPending ? 'Posting...' : 'Post'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showReverseModal}
        onClose={() => {
          setShowReverseModal(false);
          setAccrualToReverse(null);
          setReversePostingDate('');
          setReverseReason('');
        }}
        title="Reverse Accrual"
      >
        <div className="space-y-4">
          <p className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
            Reversing creates a new posting group with opposite ledger entries. The original posting is not modified. This action cannot be undone.
          </p>
          <FormField label="Posting date" required>
            <input
              type="date"
              value={reversePostingDate}
              onChange={(e) => setReversePostingDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Reason (optional)">
            <textarea
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              rows={2}
              placeholder="e.g. Correction for duplicate accrual"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
              type="button"
              onClick={() => {
                setShowReverseModal(false);
                setAccrualToReverse(null);
                setReversePostingDate('');
                setReverseReason('');
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleReverseAccrual}
              disabled={!reversePostingDate || reverseAccrualMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-md hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {reverseAccrualMutation.isPending ? 'Reversing...' : 'Reverse'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
