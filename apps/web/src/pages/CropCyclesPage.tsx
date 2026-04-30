import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useCropCycles, useCreateCropCycle, useCloseCropCycle, useOpenCropCycle } from '../hooks/useCropCycles';
import { useCropItems, useCreateCropItem } from '../hooks/useCropItems';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { Badge } from '../components/Badge';
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
      header: 'Crop cycle',
      accessor: (row) => (
        <Link to={`/app/crop-cycles/${row.id}`} className="text-[#1F6F5C] hover:text-[#1a5a4a] font-semibold">
          {row.name}
        </Link>
      ),
    },
    {
      header: 'Crop',
      accessor: (row) => row.crop_display_name ?? row.crop_type ?? '—',
    },
    {
      header: 'Start date',
      accessor: (r) => (
        <span className="tabular-nums text-gray-900">{formatDate(r.start_date, { variant: 'medium' })}</span>
      ),
    },
    {
      header: 'End date',
      accessor: (r) => (
        <span className="tabular-nums text-gray-900">
          {r.end_date ? formatDate(r.end_date, { variant: 'medium' }) : '—'}
        </span>
      ),
    },
    {
      header: 'Status',
      accessor: (row) => (
        <Badge variant={row.status === 'OPEN' ? 'success' : 'neutral'} size="md">
          {row.status === 'OPEN' ? 'Open' : 'Closed'}
        </Badge>
      ),
    },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex flex-wrap gap-2">
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

  const cycleList = cycles ?? [];
  const openCount = useMemo(() => (cycles ?? []).filter((c) => c.status === 'OPEN').length, [cycles]);
  const closedCount = useMemo(() => (cycles ?? []).filter((c) => c.status === 'CLOSED').length, [cycles]);
  const summaryLine = useMemo(() => {
    const n = (cycles ?? []).length;
    if (n === 0) return '0 crop cycles';
    return `${n} crop ${n === 1 ? 'cycle' : 'cycles'} · ${openCount} open, ${closedCount} closed`;
  }, [cycles, openCount, closedCount]);

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  const showEmpty = cycleList.length === 0;

  return (
    <div data-testid="crop-cycles-page" className="space-y-6 max-w-7xl">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Crop Cycles</h1>
          <p className="mt-1 text-base text-gray-700">Define and manage crop seasons used for planning and reporting.</p>
          <p className="mt-1 text-sm text-gray-500 max-w-2xl">
            Crop cycles organise seasonal work across land, fields, and operations.
          </p>
        </div>
        {canCreate && (
          <div className="flex flex-col sm:flex-row gap-2 shrink-0">
            <Link
              to="/app/projects/setup"
              className="inline-flex justify-center px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              Add field cycle
            </Link>
            <button
              data-testid="new-crop-cycle"
              type="button"
              onClick={() => setShowCreateModal(true)}
              className="inline-flex justify-center px-4 py-2 border border-gray-300 text-gray-800 rounded-md hover:bg-gray-50 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              Create crop cycle
            </button>
          </div>
        )}
      </div>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {showEmpty ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No crop cycles yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">Create one to start planning seasonal work.</p>
          {canCreate ? (
            <div className="mt-6 flex flex-col sm:flex-row gap-2 justify-center">
              <Link
                to="/app/projects/setup"
                className="inline-flex justify-center px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
              >
                Add field cycle
              </Link>
              <button
                type="button"
                onClick={() => setShowCreateModal(true)}
                className="inline-flex justify-center px-4 py-2 border border-gray-300 text-gray-800 rounded-md hover:bg-gray-50 text-sm font-medium"
              >
                Create crop cycle
              </button>
            </div>
          ) : null}
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable data={cycleList} columns={columns} emptyMessage="" />
        </div>
      )}

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
                <option value="">{cropItemsLoading ? 'Loading crops…' : 'Select crop'}</option>
                {(cropItems ?? []).length === 0 && !cropItemsLoading ? (
                  <option value="" disabled>
                    No crops available — add a crop first
                  </option>
                ) : null}
                {(cropItems ?? []).map((item) => {
                  const label = item.display_name || item.custom_name || item.catalog_code || item.id;
                  return (
                    <option key={item.id} value={item.id}>
                      {label}
                      {item.source === 'custom' ? ' (custom)' : ''}
                    </option>
                  );
                })}
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
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
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
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
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
