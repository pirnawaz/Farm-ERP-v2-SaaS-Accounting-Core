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
import { useFormatting } from '../../hooks/useFormatting';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { Badge } from '../../components/Badge';
import { useRole } from '../../hooks/useRole';
import toast from 'react-hot-toast';
import type { LandLease, CreateLandLeasePayload, LandLeaseFrequency } from '@farm-erp/shared';

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

const FREQUENCY_LABEL: Record<LandLeaseFrequency, string> = {
  MONTHLY: 'Monthly',
};

/** True when the lease has an end date strictly before today (local calendar). */
function isLeaseEnded(row: LandLease): boolean {
  if (!row.end_date) return false;
  const end = new Date(`${row.end_date}T12:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  end.setHours(0, 0, 0, 0);
  return end < today;
}

export default function LandLeasesPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { formatDate } = useFormatting();
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
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'ended'>('all');

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

  const allRows = leases ?? [];

  const filteredLeases = useMemo(() => {
    const q = searchQuery.trim().toLowerCase();
    return allRows.filter((row) => {
      const landlord = (row.landlord_party?.name ?? '').toLowerCase();
      const parcel = (row.land_parcel?.name ?? '').toLowerCase();
      const matchesSearch =
        !q || landlord.includes(q) || parcel.includes(q) || row.id.toLowerCase().includes(q);

      const ended = isLeaseEnded(row);
      const matchesStatus =
        statusFilter === 'all' ||
        (statusFilter === 'active' && !ended) ||
        (statusFilter === 'ended' && ended);

      return matchesSearch && matchesStatus;
    });
  }, [allRows, searchQuery, statusFilter]);

  const hasActiveFilters =
    searchQuery.trim().length > 0 || statusFilter !== 'all';

  const summaryLine = useMemo(() => {
    const n = filteredLeases.length;
    const label = n === 1 ? 'lease' : 'leases';
    const base = hasActiveFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
    if (n === 0) return base;
    const active = filteredLeases.filter((l) => !isLeaseEnded(l)).length;
    const ended = filteredLeases.filter((l) => isLeaseEnded(l)).length;
    return `${base} · ${active} active · ${ended} ended`;
  }, [filteredLeases, hasActiveFilters]);

  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('all');
  };

  const columns: Column<LandLease>[] = useMemo(
    () => [
      {
        header: 'Land parcel',
        accessor: (row) => (
          <Link
            to={`/app/land-leases/${row.id}`}
            onClick={(e) => e.stopPropagation()}
            className="font-semibold text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            {row.land_parcel?.name ?? '—'}
          </Link>
        ),
      },
      {
        header: 'Landlord',
        accessor: (row) => <span className="text-gray-900">{row.landlord_party?.name ?? '—'}</span>,
      },
      {
        header: 'Field cycle',
        accessor: (row) => (
          <span className="text-gray-800">{row.project?.name ?? '—'}</span>
        ),
      },
      {
        header: 'Payment term',
        accessor: (row) => (
          <span className="text-gray-800">{FREQUENCY_LABEL[row.frequency] ?? row.frequency}</span>
        ),
      },
      {
        header: 'Term',
        accessor: (row) => (
          <span className="tabular-nums text-gray-900 whitespace-nowrap">
            {formatDate(row.start_date, { variant: 'medium' })}
            <span className="mx-1 text-gray-400">→</span>
            {row.end_date ? formatDate(row.end_date, { variant: 'medium' }) : 'Ongoing'}
          </span>
        ),
      },
      {
        header: 'Key terms',
        accessor: (row) => {
          const n = row.notes?.trim();
          if (!n) return <span className="text-gray-400">—</span>;
          const short = n.length > 56 ? `${n.slice(0, 53)}…` : n;
          return (
            <span className="text-sm text-gray-700 max-w-xs inline-block align-top" title={n}>
              {short}
            </span>
          );
        },
      },
      {
        header: 'Status',
        accessor: (row) => (
          <Badge variant={isLeaseEnded(row) ? 'neutral' : 'success'} size="md">
            {isLeaseEnded(row) ? 'Ended' : 'Active'}
          </Badge>
        ),
      },
      {
        header: 'Actions',
        accessor: (row) => (
          <div className="flex flex-wrap gap-2">
            <Link
              to={`/app/land-leases/${row.id}`}
              className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
              onClick={(e) => e.stopPropagation()}
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
                className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
              >
                Edit
              </button>
            )}
          </div>
        ),
      },
    ],
    [canManage, formatDate],
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

  const showNoData = allRows.length === 0;
  const showFilteredEmpty = allRows.length > 0 && filteredLeases.length === 0;

  return (
    <div data-testid="land-leases-page" className="space-y-6 max-w-7xl">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Land Leases (Maqada)</h1>
          <p className="mt-1 text-base text-gray-700">
            Manage land lease agreements between your farm and landowners.
          </p>
          <p className="mt-1 text-sm text-gray-500 max-w-2xl">
            Use leases to define how land is used and what is owed under each agreement.
          </p>
        </div>
        {canManage && (
          <button
            data-testid="new-land-lease"
            type="button"
            onClick={openCreate}
            className="shrink-0 px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Lease
          </button>
        )}
      </div>

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasActiveFilters}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:max-w-3xl">
          <div>
            <label htmlFor="land-lease-search" className="block text-xs font-medium text-gray-600 mb-1">
              Search
            </label>
            <input
              id="land-lease-search"
              type="search"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Landlord or land parcel…"
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            />
          </div>
          <div>
            <label htmlFor="land-lease-status" className="block text-xs font-medium text-gray-600 mb-1">
              Status
            </label>
            <select
              id="land-lease-status"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value as 'all' | 'active' | 'ended')}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="all">All</option>
              <option value="active">Active</option>
              <option value="ended">Ended</option>
            </select>
            <p className="mt-1.5 text-xs text-gray-500">Active and ended are inferred from the lease end date.</p>
          </div>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {showNoData ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No land leases yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Add a lease to define how land is used and managed.
          </p>
          {canManage ? (
            <button
              type="button"
              onClick={openCreate}
              className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              New Lease
            </button>
          ) : null}
        </div>
      ) : showFilteredEmpty ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No leases match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try adjusting search or status, or clear filters.</p>
          <button
            type="button"
            onClick={clearFilters}
            className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable
            data={filteredLeases}
            columns={columns}
            onRowClick={(row) => navigate(`/app/land-leases/${row.id}`)}
            emptyMessage=""
          />
        </div>
      )}

      <Modal
        isOpen={showModal}
        onClose={() => {
          setShowModal(false);
          setEditingId(null);
          setFormData(defaultForm);
        }}
        title={editingId ? 'Edit lease' : 'Add lease'}
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
                  frequency: e.target.value as LandLeaseFrequency,
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
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
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
