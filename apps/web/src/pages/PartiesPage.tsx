import { useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useParties, useCreateParty, useUpdateParty, useDeleteParty } from '../hooks/useParties';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { PageHeader } from '../components/PageHeader';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { Badge } from '../components/Badge';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import toast from 'react-hot-toast';
import type { Party, CreatePartyPayload, PartyType } from '../types';

const PARTY_TYPES: PartyType[] = ['HARI', 'KAMDAR', 'VENDOR', 'BUYER', 'LENDER', 'CONTRACTOR', 'LANDLORD'];

/** Display labels for directory UI — maps existing PartyType values only. */
const ROLE_LABELS: Record<PartyType, string> = {
  HARI: 'Hari',
  KAMDAR: 'Kamdar',
  VENDOR: 'Vendor',
  BUYER: 'Buyer',
  LENDER: 'Lender',
  CONTRACTOR: 'Contractor',
  LANDLORD: 'Landlord',
};

function RoleBadges({ types }: { types: PartyType[] }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {types.map((t) => (
        <Badge key={t} variant="neutral" size="sm">
          {ROLE_LABELS[t]}
        </Badge>
      ))}
    </div>
  );
}

export default function PartiesPage() {
  const { data: parties, isLoading } = useParties();
  const createMutation = useCreateParty();
  const updateMutation = useUpdateParty();
  const deleteMutation = useDeleteParty();
  const { hasRole } = useRole();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingParty, setEditingParty] = useState<Party | null>(null);
  const [deletePartyId, setDeletePartyId] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [roleFilter, setRoleFilter] = useState<'' | PartyType>('');
  const [formData, setFormData] = useState<CreatePartyPayload>({
    name: '',
    party_types: [],
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const canCreate = hasRole(['tenant_admin', 'accountant']);

  const hasActiveFilters = useMemo(() => !!(searchQuery.trim() || roleFilter), [searchQuery, roleFilter]);

  const filteredParties = useMemo(() => {
    if (!parties?.length) return [];
    let list = parties;
    const q = searchQuery.trim().toLowerCase();
    if (q) {
      list = list.filter((p) => p.name.toLowerCase().includes(q));
    }
    if (roleFilter) {
      list = list.filter((p) => p.party_types.includes(roleFilter));
    }
    return list;
  }, [parties, searchQuery, roleFilter]);

  const totalPeople = parties?.length ?? 0;
  const visibleCount = filteredParties.length;

  const clearFilters = () => {
    setSearchQuery('');
    setRoleFilter('');
  };

  const isSystemLandlord = (party: Party): boolean => {
    return party.party_types.includes('LANDLORD') || party.name.toLowerCase() === 'landlord';
  };

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};
    if (!formData.name.trim()) {
      newErrors.name = 'Name is required';
    }
    if (formData.party_types.length === 0) {
      newErrors.party_types = 'At least one party type must be selected';
    }
    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleCreate = async () => {
    if (!validateForm()) return;

    try {
      await createMutation.mutateAsync(formData);
      setShowCreateModal(false);
      setFormData({ name: '', party_types: [] });
      setErrors({});
    } catch (error: unknown) {
      const msg = error instanceof Error ? error.message : 'Failed to create party';
      toast.error(msg);
    }
  };

  const handleEdit = (party: Party) => {
    setEditingParty(party);
    setFormData({
      name: party.name,
      party_types: party.party_types,
    });
    setErrors({});
  };

  const handleUpdate = async () => {
    if (!editingParty || !validateForm()) return;

    try {
      await updateMutation.mutateAsync({
        id: editingParty.id,
        payload: formData,
      });
      toast.success('Party updated successfully');
      setEditingParty(null);
      setFormData({ name: '', party_types: [] });
      setErrors({});
    } catch (error: unknown) {
      const msg = error instanceof Error ? error.message : 'Failed to update party';
      toast.error(msg);
    }
  };

  const handleDelete = async (id: string) => {
    try {
      await deleteMutation.mutateAsync(id);
      toast.success('Party deleted successfully');
      setDeletePartyId(null);
    } catch (error: unknown) {
      const msg = error instanceof Error ? error.message : 'Failed to delete party';
      toast.error(msg);
    }
  };

  const togglePartyType = (type: PartyType) => {
    setFormData((prev) => {
      const newTypes = prev.party_types.includes(type)
        ? prev.party_types.filter((t) => t !== type)
        : [...prev.party_types, type];
      return { ...prev, party_types: newTypes };
    });
    if (errors.party_types) {
      setErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors.party_types;
        return newErrors;
      });
    }
  };

  const { formatDate } = useFormatting();

  const columns: Column<Party>[] = [
    {
      header: 'Name',
      accessor: (row) => (
        <div>
          <Link to={`/app/parties/${row.id}`} className="text-[#1F6F5C] hover:text-[#1a5a4a] font-semibold text-gray-900">
            {row.name}
          </Link>
        </div>
      ),
    },
    {
      header: 'Roles',
      accessor: (row) => <RoleBadges types={row.party_types} />,
    },
    {
      header: 'Created',
      accessor: (row) => <span className="tabular-nums text-gray-700">{formatDate(row.created_at)}</span>,
      cellClassName: 'whitespace-nowrap',
    },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex flex-wrap gap-2">
          <Link to={`/app/parties/${row.id}`} className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]">
            View
          </Link>
          {canCreate && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                handleEdit(row);
              }}
              className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
            >
              Edit
            </button>
          )}
          {canCreate && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                setDeletePartyId(row.id);
              }}
              disabled={isSystemLandlord(row)}
              className={`text-sm font-medium ${
                isSystemLandlord(row) ? 'text-gray-400 cursor-not-allowed' : 'text-red-600 hover:text-red-900'
              }`}
              title={isSystemLandlord(row) ? 'System party' : 'Delete'}
            >
              Delete
            </button>
          )}
        </div>
      ),
    },
  ];

  const openCreate = () => {
    setShowCreateModal(true);
    setFormData({ name: '', party_types: [] });
    setErrors({});
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  const summaryLine =
    hasActiveFilters && totalPeople > 0
      ? `${visibleCount} ${visibleCount === 1 ? 'person' : 'people'} (filtered)`
      : `${visibleCount} ${visibleCount === 1 ? 'person' : 'people'}`;

  const showFilteredEmpty = totalPeople > 0 && visibleCount === 0;
  const showNoData = totalPeople === 0;

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title="People & Partners"
        description="Manage all people and partners you work with — workers, vendors, buyers, and landlords."
        helper="Use this directory to maintain contact records and roles across your farm operations."
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'People & Partners' }]}
        right={
          canCreate ? (
            <button
              type="button"
              onClick={openCreate}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              Add person
            </button>
          ) : undefined
        }
      />

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
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div>
            <label htmlFor="parties-search" className="block text-xs font-medium text-gray-600 mb-1">
              Search by name
            </label>
            <input
              id="parties-search"
              type="search"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search…"
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            />
          </div>
          <div>
            <label htmlFor="parties-role" className="block text-xs font-medium text-gray-600 mb-1">
              Role
            </label>
            <select
              id="parties-role"
              value={roleFilter}
              onChange={(e) => setRoleFilter((e.target.value || '') as '' | PartyType)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            >
              <option value="">All roles</option>
              {PARTY_TYPES.map((t) => (
                <option key={t} value={t}>
                  {ROLE_LABELS[t]}
                </option>
              ))}
            </select>
          </div>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {showNoData ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No people or partners yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Add your first person to start managing relationships.
          </p>
          {canCreate ? (
            <button
              type="button"
              onClick={openCreate}
              className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              Add person
            </button>
          ) : null}
        </div>
      ) : showFilteredEmpty ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No people match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try adjusting search or role, or clear filters to see everyone.</p>
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
          <DataTable data={filteredParties} columns={columns} emptyMessage="" />
        </div>
      )}

      <Modal
        isOpen={showCreateModal}
        onClose={() => {
          setShowCreateModal(false);
          setFormData({ name: '', party_types: [] });
          setErrors({});
        }}
        title="Add person"
      >
        <div className="space-y-4">
          <FormField label="Name" required error={errors.name}>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => {
                setFormData({ ...formData, name: e.target.value });
                if (errors.name) {
                  setErrors((prev) => {
                    const newErrors = { ...prev };
                    delete newErrors.name;
                    return newErrors;
                  });
                }
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Party Types" required error={errors.party_types}>
            <div className="space-y-2">
              {PARTY_TYPES.map((type) => (
                <label key={type} className="flex items-center">
                  <input
                    type="checkbox"
                    checked={formData.party_types.includes(type)}
                    onChange={() => togglePartyType(type)}
                    className="mr-2 h-4 w-4 text-[#1F6F5C] focus:ring-[#1F6F5C] border-gray-300 rounded"
                  />
                  <span className="text-sm text-gray-700">{ROLE_LABELS[type]}</span>
                </label>
              ))}
            </div>
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
            <button
              type="button"
              onClick={() => {
                setShowCreateModal(false);
                setFormData({ name: '', party_types: [] });
                setErrors({});
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleCreate}
              disabled={createMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createMutation.isPending ? 'Creating…' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={!!editingParty}
        onClose={() => {
          setEditingParty(null);
          setFormData({ name: '', party_types: [] });
          setErrors({});
        }}
        title="Edit person"
      >
        <div className="space-y-4">
          <FormField label="Name" required error={errors.name}>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => {
                setFormData({ ...formData, name: e.target.value });
                if (errors.name) {
                  setErrors((prev) => {
                    const newErrors = { ...prev };
                    delete newErrors.name;
                    return newErrors;
                  });
                }
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Party Types" required error={errors.party_types}>
            <div className="space-y-2">
              {PARTY_TYPES.map((type) => (
                <label key={type} className="flex items-center">
                  <input
                    type="checkbox"
                    checked={formData.party_types.includes(type)}
                    onChange={() => togglePartyType(type)}
                    className="mr-2 h-4 w-4 text-[#1F6F5C] focus:ring-[#1F6F5C] border-gray-300 rounded"
                  />
                  <span className="text-sm text-gray-700">{ROLE_LABELS[type]}</span>
                </label>
              ))}
            </div>
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
            <button
              type="button"
              onClick={() => {
                setEditingParty(null);
                setFormData({ name: '', party_types: [] });
                setErrors({});
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleUpdate}
              disabled={updateMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {updateMutation.isPending ? 'Updating…' : 'Update'}
            </button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        isOpen={!!deletePartyId}
        onClose={() => setDeletePartyId(null)}
        onConfirm={() => {
          if (deletePartyId) {
            handleDelete(deletePartyId);
          }
        }}
        title="Delete Party"
        message="Are you sure you want to delete this party? This action cannot be undone."
        confirmText="Delete"
        cancelText="Cancel"
        variant="danger"
      />
    </div>
  );
}
