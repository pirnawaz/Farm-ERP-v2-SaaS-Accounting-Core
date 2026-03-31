import { useState } from 'react';
import { Link } from 'react-router-dom';
import { term } from '../config/terminology';
import { useCropCycles, useCreateCropCycle, useCloseCropCycle, useOpenCropCycle } from '../hooks/useCropCycles';
import { useCropItems, useCreateCropItem } from '../hooks/useCropItems';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import toast from 'react-hot-toast';
import type { CropCycle, CreateCropCyclePayload } from '../types';

const initialFormData: CreateCropCyclePayload = {
  name: '',
  tenant_crop_item_id: '',
  start_date: '',
  end_date: '',
};

export default function CropCyclesPage() {
  const { data: cycles, isLoading } = useCropCycles();
  const { data: cropItems, isLoading: cropItemsLoading } = useCropItems();
  const createMutation = useCreateCropCycle();
  const createCropItemMutation = useCreateCropItem();
  const closeMutation = useCloseCropCycle();
  const openMutation = useOpenCropCycle();
  const { hasRole, canCloseCropCycle } = useRole();
  const { formatDate } = useFormatting();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [showAddCropModal, setShowAddCropModal] = useState(false);
  const [confirmAction, setConfirmAction] = useState<{ type: 'close' | 'open'; id: string } | null>(null);
  const [formData, setFormData] = useState<CreateCropCyclePayload>(initialFormData);
  const [newCropName, setNewCropName] = useState('');

  const canCreate = hasRole(['tenant_admin', 'accountant']);
  const canAddCrop = hasRole(['tenant_admin', 'accountant']);

  const handleClose = async (id: string) => {
    try {
      await closeMutation.mutateAsync({ id });
      toast.success('Crop cycle closed successfully');
    } catch (error: any) {
      const msg = error?.response?.data?.message ?? error.message ?? 'Failed to close crop cycle';
      toast.error(msg);
    }
  };

  const handleOpen = async (id: string) => {
    try {
      await openMutation.mutateAsync(id);
      toast.success('Crop cycle opened successfully');
    } catch (error: any) {
      toast.error(error.message || 'Failed to open crop cycle');
    }
  };

  const handleCreate = async () => {
    try {
      const payload: CreateCropCyclePayload = {
        name: formData.name,
        tenant_crop_item_id: formData.tenant_crop_item_id,
        start_date: formData.start_date,
        ...(formData.end_date?.trim() && { end_date: formData.end_date.trim() }),
      };
      await createMutation.mutateAsync(payload);
      toast.success('Crop cycle created successfully');
      setShowCreateModal(false);
      setFormData(initialFormData);
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? 'Failed to create crop cycle');
    }
  };

  const handleAddCrop = async () => {
    const name = newCropName.trim();
    if (!name) {
      toast.error('Enter a crop name');
      return;
    }
    try {
      await createCropItemMutation.mutateAsync({ custom_name: name });
      toast.success('Crop added');
      setNewCropName('');
      setShowAddCropModal(false);
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? 'Failed to add crop');
    }
  };

  const columns: Column<CropCycle>[] = [
    {
      header: 'Name',
      accessor: (row) => (
        <Link to={`/app/crop-cycles/${row.id}`} className="text-[#1F6F5C] hover:text-[#1a5a4a] font-medium">
          {row.name}
        </Link>
      ),
    },
    {
      header: 'Crop',
      accessor: (row) => row.crop_display_name ?? row.crop_type ?? '—',
    },
    {
      header: 'Start Date',
      accessor: (r) => formatDate(r.start_date, { variant: 'medium' }),
    },
    {
      header: 'End Date',
      accessor: (r) => (r.end_date ? formatDate(r.end_date, { variant: 'medium' }) : '—'),
    },
    { header: 'Status', accessor: 'status' },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex space-x-2">
          {canCloseCropCycle && row.status === 'OPEN' && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                setConfirmAction({ type: 'close', id: row.id });
              }}
              className="text-orange-600 hover:text-orange-900"
            >
              Close
            </button>
          )}
          {canCloseCropCycle && row.status === 'CLOSED' && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                setConfirmAction({ type: 'open', id: row.id });
              }}
              className="text-green-600 hover:text-green-900"
            >
              Open
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
    <div data-testid="crop-cycles-page">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Crop Cycles</h1>
        {canCreate && (
          <div className="flex gap-2">
            <Link
              to="/app/crop-cycles/season-setup"
              data-testid="season-setup-cta"
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              {term('assignFieldsToSeason')}
            </Link>
            <button
              data-testid="new-crop-cycle"
              onClick={() => setShowCreateModal(true)}
              className="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              New crop cycle (no fields)
            </button>
          </div>
        )}
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable data={cycles || []} columns={columns} />
      </div>

      <Modal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title="Create Crop Cycle"
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
          <FormField label="Crop" required>
            <div className="flex gap-2">
              <select
                value={formData.tenant_crop_item_id}
                onChange={(e) => setFormData({ ...formData, tenant_crop_item_id: e.target.value })}
                className="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                disabled={cropItemsLoading}
              >
                <option value="">Select crop</option>
                {(cropItems ?? []).map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.display_name}
                    {item.source === 'custom' ? ' (custom)' : ''}
                  </option>
                ))}
              </select>
              {canAddCrop && (
                <button
                  type="button"
                  onClick={() => setShowAddCropModal(true)}
                  className="px-3 py-2 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
                  title="Add custom crop"
                >
                  + Add crop
                </button>
              )}
            </div>
          </FormField>
          <FormField label="Start Date" required>
            <input
              type="date"
              value={formData.start_date}
              onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="End Date">
            <input
              type="date"
              value={formData.end_date}
              onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
            <p className="text-xs text-gray-500 mt-1">Leave blank until cycle ends. You lock the cycle when you close it.</p>
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
              disabled={
                createMutation.isPending ||
                !formData.name ||
                !formData.start_date ||
                !formData.tenant_crop_item_id
              }
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createMutation.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>

      {canAddCrop && (
        <Modal
          isOpen={showAddCropModal}
          onClose={() => setShowAddCropModal(false)}
          title="Add custom crop"
        >
          <div className="space-y-4">
            <FormField label="Crop name" required>
              <input
                type="text"
                value={newCropName}
                onChange={(e) => setNewCropName(e.target.value)}
                placeholder="e.g. Local maize variety"
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              />
            </FormField>
            <div className="flex justify-end space-x-3">
              <button
                onClick={() => setShowAddCropModal(false)}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handleAddCrop}
                disabled={createCropItemMutation.isPending || !newCropName.trim()}
                className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {createCropItemMutation.isPending ? 'Adding...' : 'Add'}
              </button>
            </div>
          </div>
        </Modal>
      )}

      <ConfirmDialog
        isOpen={!!confirmAction}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => {
          if (confirmAction) {
            if (confirmAction.type === 'close') {
              handleClose(confirmAction.id);
            } else {
              handleOpen(confirmAction.id);
            }
            setConfirmAction(null);
          }
        }}
        title={confirmAction?.type === 'close' ? 'Close Crop Cycle' : 'Open Crop Cycle'}
        message={
          confirmAction?.type === 'close'
            ? 'Are you sure you want to close this crop cycle?'
            : 'Are you sure you want to open this crop cycle?'
        }
      />
    </div>
  );
}
