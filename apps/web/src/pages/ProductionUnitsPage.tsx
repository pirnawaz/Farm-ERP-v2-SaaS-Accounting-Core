import { useState } from 'react';
import { useProductionUnits, useCreateProductionUnit } from '../hooks/useProductionUnits';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { PageHeader } from '../components/PageHeader';
import toast from 'react-hot-toast';
import type { ProductionUnit, CreateProductionUnitPayload } from '../types';

const initialFormData: CreateProductionUnitPayload = {
  name: '',
  type: 'SEASONAL',
  start_date: new Date().toISOString().split('T')[0],
  end_date: null,
  notes: null,
};

export default function ProductionUnitsPage() {
  const { data: units, isLoading } = useProductionUnits();
  const createMutation = useCreateProductionUnit();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [formData, setFormData] = useState<CreateProductionUnitPayload>(initialFormData);

  const handleCreate = async () => {
    const name = formData.name?.trim();
    if (!name) {
      toast.error('Name is required');
      return;
    }
    try {
      await createMutation.mutateAsync({
        name,
        type: formData.type,
        start_date: formData.start_date,
        end_date: formData.end_date?.trim() || undefined,
        notes: formData.notes?.trim() || undefined,
      });
      toast.success('Production unit created');
      setShowCreateModal(false);
      setFormData(initialFormData);
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string }; status?: number }; message?: string };
      toast.error(err?.response?.data?.message ?? err?.message ?? 'Failed to create production unit');
    }
  };

  const columns: Column<ProductionUnit>[] = [
    { header: 'Name', accessor: 'name' },
    { header: 'Type', accessor: 'type' },
    { header: 'Start Date', accessor: 'start_date' },
    { header: 'End Date', accessor: (r) => r.end_date ?? '—' },
    { header: 'Status', accessor: 'status' },
  ];

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div data-testid="production-units-page">
      <PageHeader
        title="Production Units"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Production Units' },
        ]}
      />
      <div className="flex justify-between items-center mb-6">
        <p className="text-gray-600">Track long-duration crops (e.g. Sugarcane) across multiple crop cycles.</p>
        <button
          data-testid="new-production-unit"
          onClick={() => setShowCreateModal(true)}
          className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
        >
          New Production Unit
        </button>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable columns={columns} data={units ?? []} emptyMessage="No production units yet. Create one to get started." />
      </div>

      <Modal
        isOpen={showCreateModal}
        onClose={() => { setShowCreateModal(false); setFormData(initialFormData); }}
        title="New Production Unit"
      >
        <div className="space-y-4">
          <FormField label="Name" required>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData((p) => ({ ...p, name: e.target.value }))}
              placeholder="e.g. Sugarcane Plant 2025"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Type">
            <select
              value={formData.type}
              onChange={(e) => setFormData((p) => ({ ...p, type: e.target.value as 'SEASONAL' | 'LONG_CYCLE' }))}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            >
              <option value="SEASONAL">SEASONAL</option>
              <option value="LONG_CYCLE">LONG_CYCLE</option>
            </select>
          </FormField>
          <FormField label="Start Date" required>
            <input
              type="date"
              value={formData.start_date}
              onChange={(e) => setFormData((p) => ({ ...p, start_date: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="End Date (optional)">
            <input
              type="date"
              value={formData.end_date ?? ''}
              onChange={(e) => setFormData((p) => ({ ...p, end_date: e.target.value || null }))}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Notes (optional)">
            <textarea
              value={formData.notes ?? ''}
              onChange={(e) => setFormData((p) => ({ ...p, notes: e.target.value || null }))}
              rows={2}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <button
            type="button"
            onClick={() => { setShowCreateModal(false); setFormData(initialFormData); }}
            className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleCreate}
            disabled={createMutation.isPending || !formData.name?.trim()}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {createMutation.isPending ? 'Creating...' : 'Create'}
          </button>
        </div>
      </Modal>
    </div>
  );
}
