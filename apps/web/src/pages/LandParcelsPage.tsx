import { useState, useMemo } from 'react';
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
  const [searchQuery, setSearchQuery] = useState('');
  const [formData, setFormData] = useState<CreateLandParcelPayload>({
    name: '',
    total_acres: '',
    notes: '',
  });

  const canCreate =
    import.meta.env.MODE === 'e2e' || hasRole(['tenant_admin', 'accountant']);

  const hasActiveFilters = searchQuery.trim().length > 0;

  const filteredParcels = useMemo(() => {
    const list = parcels ?? [];
    const q = searchQuery.trim().toLowerCase();
    if (!q) return list;
    return list.filter(
      (p) =>
        p.name.toLowerCase().includes(q) ||
        (p.notes || '').toLowerCase().includes(q) ||
        String(p.total_acres).includes(q),
    );
  }, [parcels, searchQuery]);

  const clearFilters = () => setSearchQuery('');

  const columns: Column<LandParcel>[] = [
    {
      header: 'Land parcel',
      accessor: (row) => (
        <div>
          <Link
            to={`/app/land/${row.id}`}
            className="font-semibold text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            {row.name}
          </Link>
          {row.notes?.trim() ? (
            <p className="mt-0.5 text-xs text-gray-500 max-w-md truncate" title={row.notes}>
              {row.notes.trim().length > 80 ? `${row.notes.trim().slice(0, 77)}…` : row.notes.trim()}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      header: 'Total acres',
      accessor: (row) => (
        <span className="tabular-nums text-gray-900 text-right block">{row.total_acres}</span>
      ),
      numeric: true,
      align: 'right',
    },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex flex-wrap gap-2">
          <Link to={`/app/land/${row.id}`} className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]">
            View
          </Link>
          {canCreate && (
            <Link to={`/app/land/${row.id}/edit`} className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]">
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
    } catch (error: unknown) {
      const msg = error instanceof Error ? error.message : 'Failed to create land parcel';
      toast.error(msg);
    }
  };

  const openCreate = () => setShowCreateModal(true);

  const total = parcels?.length ?? 0;
  const visible = filteredParcels.length;
  const summaryLine =
    hasActiveFilters && total > 0
      ? `${visible} land ${visible === 1 ? 'parcel' : 'parcels'} (filtered)`
      : `${visible} land ${visible === 1 ? 'parcel' : 'parcels'}`;

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  const showNoData = total === 0;
  const showFilteredEmpty = total > 0 && visible === 0;

  return (
    <div data-testid="land-parcels-page" className="space-y-6 max-w-7xl">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Land Parcels</h1>
          <p className="mt-1 text-base text-gray-700">Manage the land parcels used across your farm.</p>
          <p className="mt-1 text-sm text-gray-500 max-w-2xl">
            Use land parcels to define the physical areas available for planning and operations.
          </p>
        </div>
        {canCreate && (
          <button
            data-testid="new-land-parcel"
            type="button"
            onClick={openCreate}
            className="shrink-0 px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            Add Land Parcel
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
        <div className="max-w-md">
          <label htmlFor="land-parcel-search" className="block text-xs font-medium text-gray-600 mb-1">
            Search
          </label>
          <input
            id="land-parcel-search"
            type="search"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Search by name or notes…"
            className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
          />
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {showNoData ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No land parcels yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">Add one to start organising farm land.</p>
          {canCreate ? (
            <button
              type="button"
              onClick={openCreate}
              className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              Add Land Parcel
            </button>
          ) : null}
        </div>
      ) : showFilteredEmpty ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No land parcels match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try a different search or clear filters.</p>
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
          <DataTable data={filteredParcels} columns={columns} onRowClick={(row) => navigate(`/app/land/${row.id}`)} emptyMessage="" />
        </div>
      )}

      <Modal isOpen={showCreateModal} onClose={() => setShowCreateModal(false)} title="Add land parcel">
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
              value={formData.total_acres}
              onChange={(e) => setFormData({ ...formData, total_acres: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Notes">
            <textarea
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
            <button
              type="button"
              onClick={() => setShowCreateModal(false)}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleCreate}
              disabled={createMutation.isPending || !formData.name || !formData.total_acres}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createMutation.isPending ? 'Creating…' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
