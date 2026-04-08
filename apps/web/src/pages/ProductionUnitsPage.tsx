import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useProductionUnits, useCreateProductionUnit } from '../hooks/useProductionUnits';
import { useOrchardLivestockAddonsEnabled } from '../hooks/useModules';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { PageHeader } from '../components/PageHeader';
import { Badge } from '../components/Badge';
import toast from 'react-hot-toast';
import type { ProductionUnit, CreateProductionUnitPayload } from '../types';

const initialFormData: CreateProductionUnitPayload = {
  name: '',
  type: 'LONG_CYCLE',
  start_date: new Date().toISOString().split('T')[0],
  end_date: null,
  notes: null,
};

function categoryLabel(c?: string | null): string {
  if (c === 'ORCHARD') return 'Orchard';
  if (c === 'LIVESTOCK') return 'Livestock';
  return '—';
}

export default function ProductionUnitsPage() {
  const { hasOrchardLivestockModule } = useOrchardLivestockAddonsEnabled();
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
        type: 'LONG_CYCLE',
        start_date: formData.start_date,
        end_date: formData.end_date?.trim() || undefined,
        notes: formData.notes?.trim() || undefined,
      });
      toast.success('Long-cycle production unit created');
      setShowCreateModal(false);
      setFormData(initialFormData);
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string }; status?: number }; message?: string };
      toast.error(err?.response?.data?.message ?? err?.message ?? 'Failed to create production unit');
    }
  };

  const columns: Column<ProductionUnit>[] = useMemo(
    () => [
      { header: 'Name', accessor: 'name' },
      {
        header: 'Category',
        accessor: (r) => categoryLabel(r.category),
      },
      {
        header: 'Type',
        accessor: (r) =>
          r.type === 'SEASONAL' ? (
            <span className="inline-flex items-center gap-2 flex-wrap">
              <span>{r.type}</span>
              <Badge variant="warning" title="Older seasonal-style unit; not recommended for new use">
                Legacy
              </Badge>
            </span>
          ) : (
            r.type
          ),
      },
      { header: 'Start Date', accessor: 'start_date' },
      { header: 'End Date', accessor: (r) => r.end_date ?? '—' },
      { header: 'Status', accessor: 'status' },
    ],
    []
  );

  if (!hasOrchardLivestockModule) {
    return (
      <div data-testid="production-units-page">
        <PageHeader
          title="Production Units (Advanced)"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Production Units (Advanced)' },
          ]}
        />
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 space-y-2">
          <p>
            This screen is available when the <strong>Orchards</strong> or <strong>Livestock</strong> add-on is enabled for your farm.
            Production units are optional long-lived operational labels used with those modules (and similar long-cycle use cases).
          </p>
          <p>
            <Link to="/app/admin/modules" className="font-medium text-[#1F6F5C] hover:underline">
              Open Modules
            </Link>{' '}
            to enable an add-on, or continue using <strong>Crop Cycle</strong> and <strong>Field Cycle</strong> for normal seasonal crops.
          </p>
        </div>
      </div>
    );
  }

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
        title="Production Units (Advanced)"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Production Units (Advanced)' },
        ]}
      />
      <div className="flex flex-col gap-4 mb-6">
        <div className="text-gray-600 space-y-2 max-w-4xl">
          <p>
            Production units are <strong>optional</strong> labels for <strong>long-lived operations</strong> that span or outlive a single crop
            cycle — mainly orchards, livestock herds, and other multi-year or continuous units. They support operational reporting across
            cycles; they are <strong>not</strong> the primary way to model normal seasonal field crops.
          </p>
          <p>
            For typical seasonal workflows, use <strong>Crop Cycle</strong> and <strong>Field Cycle</strong> only — you usually do{' '}
            <strong>not</strong> need a production unit. Prefer the Orchards and Livestock areas to create well-formed units; use this page
            for advanced long-cycle setup when needed.
          </p>
        </div>
        <div>
          <button
            data-testid="new-production-unit"
            type="button"
            onClick={() => setShowCreateModal(true)}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New long-cycle production unit
          </button>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          columns={columns}
          data={units ?? []}
          emptyMessage="No production units yet. Create orchard or livestock units from their modules, or add a generic long-cycle unit here if your operation needs one."
        />
      </div>

      <Modal
        isOpen={showCreateModal}
        onClose={() => {
          setShowCreateModal(false);
          setFormData(initialFormData);
        }}
        title="New long-cycle production unit"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            New units are always <strong>LONG_CYCLE</strong>. Seasonal crop seasons belong in Crop Cycle + Field Cycle — not as production
            units.
          </p>
          <FormField label="Name" required>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData((p) => ({ ...p, name: e.target.value }))}
              placeholder="e.g. Sugarcane block (multi-year) or support unit name"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
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
            <p className="mt-1 text-xs text-gray-500">
              For orchards and livestock, the dedicated modules are usually easier than creating units from this form alone.
            </p>
          </FormField>
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <button
            type="button"
            onClick={() => {
              setShowCreateModal(false);
              setFormData(initialFormData);
            }}
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
