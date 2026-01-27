import { useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useParties, useCreateParty, useUpdateParty, useDeleteParty } from '../hooks/useParties';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import toast from 'react-hot-toast';
import type { Party, CreatePartyPayload, PartyType } from '../types';

type FilterType = 'all' | 'haris' | 'vendors' | 'buyers' | 'kamdars';

const PARTY_TYPES: PartyType[] = ['HARI', 'KAMDAR', 'VENDOR', 'BUYER', 'LENDER', 'CONTRACTOR', 'LANDLORD'];

export default function PartiesPage() {
  const { data: parties, isLoading } = useParties();
  const createMutation = useCreateParty();
  const updateMutation = useUpdateParty();
  const deleteMutation = useDeleteParty();
  const { hasRole } = useRole();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingParty, setEditingParty] = useState<Party | null>(null);
  const [deletePartyId, setDeletePartyId] = useState<string | null>(null);
  const [filter, setFilter] = useState<FilterType>('all');
  const [formData, setFormData] = useState<CreatePartyPayload>({
    name: '',
    party_types: [],
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const canCreate = hasRole(['tenant_admin', 'accountant']);

  // Filter parties based on selected filter
  const filteredParties = useMemo(() => {
    if (!parties) return [];
    if (filter === 'all') return parties;
    if (filter === 'haris') return parties.filter((p) => p.party_types.includes('HARI'));
    if (filter === 'vendors') return parties.filter((p) => p.party_types.includes('VENDOR'));
    if (filter === 'buyers') return parties.filter((p) => p.party_types.includes('BUYER'));
    if (filter === 'kamdars') return parties.filter((p) => p.party_types.includes('KAMDAR'));
    return parties;
  }, [parties, filter]);

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
      toast.success('Party created successfully');
      setShowCreateModal(false);
      setFormData({ name: '', party_types: [] });
      setErrors({});
    } catch (error: any) {
      toast.error(error.message || 'Failed to create party');
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
    } catch (error: any) {
      toast.error(error.message || 'Failed to update party');
    }
  };

  const handleDelete = async (id: string) => {
    try {
      await deleteMutation.mutateAsync(id);
      toast.success('Party deleted successfully');
      setDeletePartyId(null);
    } catch (error: any) {
      toast.error(error.message || 'Failed to delete party');
    }
  };

  const togglePartyType = (type: PartyType) => {
    setFormData((prev) => {
      const newTypes = prev.party_types.includes(type)
        ? prev.party_types.filter((t) => t !== type)
        : [...prev.party_types, type];
      return { ...prev, party_types: newTypes };
    });
    // Clear error when user selects a type
    if (errors.party_types) {
      setErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors.party_types;
        return newErrors;
      });
    }
  };

  const formatPartyTypes = (types: PartyType[]): string => {
    return types.join(', ');
  };

  const { formatDate } = useFormatting();

  const columns: Column<Party>[] = [
    {
      header: 'Name',
      accessor: (row) => (
        <Link
          to={`/app/parties/${row.id}`}
          className="text-[#1F6F5C] hover:text-[#1a5a4a] font-medium"
        >
          {row.name}
        </Link>
      ),
    },
    {
      header: 'Types',
      accessor: (row) => formatPartyTypes(row.party_types),
    },
    {
      header: 'Created At',
      accessor: (row) => formatDate(row.created_at),
    },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex space-x-2">
          <Link
            to={`/app/parties/${row.id}`}
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View
          </Link>
          {canCreate && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                handleEdit(row);
              }}
              className="text-[#1F6F5C] hover:text-[#1a5a4a]"
            >
              Edit
            </button>
          )}
          {canCreate && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                setDeletePartyId(row.id);
              }}
              disabled={isSystemLandlord(row)}
              className={`${
                isSystemLandlord(row)
                  ? 'text-gray-400 cursor-not-allowed'
                  : 'text-red-600 hover:text-red-900'
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
        <h1 className="text-2xl font-bold text-gray-900">Parties</h1>
        {canCreate && (
          <button
            onClick={() => {
              setShowCreateModal(true);
              setFormData({ name: '', party_types: [] });
              setErrors({});
            }}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Party
          </button>
        )}
      </div>

      {/* Quick Filters */}
      <div className="mb-4 flex space-x-2">
        <button
          onClick={() => setFilter('all')}
          className={`px-4 py-2 text-sm font-medium rounded-md ${
            filter === 'all'
              ? 'bg-[#1F6F5C] text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          }`}
        >
          All
        </button>
        <button
          onClick={() => setFilter('haris')}
          className={`px-4 py-2 text-sm font-medium rounded-md ${
            filter === 'haris'
              ? 'bg-[#1F6F5C] text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          }`}
        >
          Haris
        </button>
        <button
          onClick={() => setFilter('vendors')}
          className={`px-4 py-2 text-sm font-medium rounded-md ${
            filter === 'vendors'
              ? 'bg-[#1F6F5C] text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          }`}
        >
          Vendors
        </button>
        <button
          onClick={() => setFilter('buyers')}
          className={`px-4 py-2 text-sm font-medium rounded-md ${
            filter === 'buyers'
              ? 'bg-[#1F6F5C] text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          }`}
        >
          Buyers
        </button>
        <button
          onClick={() => setFilter('kamdars')}
          className={`px-4 py-2 text-sm font-medium rounded-md ${
            filter === 'kamdars'
              ? 'bg-[#1F6F5C] text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          }`}
        >
          Kamdars
        </button>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable data={filteredParties} columns={columns} emptyMessage="No parties found" />
      </div>

      {/* Create Modal */}
      <Modal
        isOpen={showCreateModal}
        onClose={() => {
          setShowCreateModal(false);
          setFormData({ name: '', party_types: [] });
          setErrors({});
        }}
        title="Create Party"
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
                  <span className="text-sm text-gray-700">{type}</span>
                </label>
              ))}
            </div>
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
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
              onClick={handleCreate}
              disabled={createMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createMutation.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>

      {/* Edit Modal */}
      <Modal
        isOpen={!!editingParty}
        onClose={() => {
          setEditingParty(null);
          setFormData({ name: '', party_types: [] });
          setErrors({});
        }}
        title="Edit Party"
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
                  <span className="text-sm text-gray-700">{type}</span>
                </label>
              ))}
            </div>
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
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
              onClick={handleUpdate}
              disabled={updateMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {updateMutation.isPending ? 'Updating...' : 'Update'}
            </button>
          </div>
        </div>
      </Modal>

      {/* Delete Confirmation */}
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
