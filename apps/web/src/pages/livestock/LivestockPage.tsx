import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useProductionUnits, useCreateProductionUnit } from '../../hooks/useProductionUnits';
import { PageHeader } from '../../components/PageHeader';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Badge } from '../../components/Badge';
import { useOrchardLivestockAddonsEnabled } from '../../hooks/useModules';
import toast from 'react-hot-toast';
import type { ProductionUnit, CreateProductionUnitPayload } from '../../types';

type LivestockFormState = CreateProductionUnitPayload & { livestock_type?: string; herd_start_count?: number | null };

const initialLivestockForm: LivestockFormState = {
  name: '',
  type: 'LONG_CYCLE',
  start_date: new Date().toISOString().split('T')[0],
  end_date: null,
  notes: null,
  category: 'LIVESTOCK',
  livestock_type: 'GOAT',
  herd_start_count: 0,
};

export default function LivestockPage() {
  const { showLivestock } = useOrchardLivestockAddonsEnabled();
  const { data: livestockUnits, isLoading } = useProductionUnits({ category: 'LIVESTOCK' });
  const createMutation = useCreateProductionUnit();
  const [showNewModal, setShowNewModal] = useState(false);
  const [form, setForm] = useState<LivestockFormState>(initialLivestockForm);

  const handleCreate = async () => {
    const name = form.name?.trim();
    if (!name) {
      toast.error('Name is required');
      return;
    }
    try {
      await createMutation.mutateAsync({
        name,
        type: 'LONG_CYCLE',
        start_date: form.start_date,
        end_date: form.end_date?.trim() || undefined,
        notes: form.notes?.trim() || undefined,
        category: 'LIVESTOCK',
        livestock_type: form.livestock_type?.trim() || undefined,
        herd_start_count: form.herd_start_count ?? undefined,
      });
      toast.success('Livestock unit created');
      setShowNewModal(false);
      setForm(initialLivestockForm);
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string }; status?: number }; message?: string };
      toast.error(err?.response?.data?.message ?? err?.message ?? 'Failed to create livestock unit');
    }
  };

  const units = livestockUnits ?? [];

  if (!showLivestock) {
    return (
      <div className="space-y-6" data-testid="livestock-page">
        <PageHeader
          title="Livestock"
          backTo="/app/dashboard"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Livestock' },
          ]}
        />
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
          Livestock module is not enabled for this tenant.
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-7xl" data-testid="livestock-page">
      <PageHeader
        title="Livestock"
        description="Long-lived herd or flock units for events, costs, and sales tagging."
        helper="Use livestock units when you want operations tied to a specific herd over time."
        backTo="/app/dashboard"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Livestock' },
        ]}
        right={
          <button
            type="button"
            data-testid="new-livestock-unit"
            onClick={() => setShowNewModal(true)}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New livestock unit
          </button>
        }
      />

      {!isLoading ? (
        <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
          <span className="font-medium text-gray-900">
            {units.length === 1 ? '1 livestock unit' : `${units.length} livestock units`}
          </span>
        </div>
      ) : null}

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : units.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No livestock units yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Create a unit to track herd events and tag feed, medicine, and sales where it helps.
          </p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {units.map((unit: ProductionUnit) => (
            <div
              key={unit.id}
              className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm flex flex-col"
            >
              <div className="font-semibold text-gray-900">{unit.name}</div>
              <div className="mt-1 text-sm text-gray-600">
                {unit.livestock_type && <span>{unit.livestock_type}</span>}
                {unit.herd_start_count != null && (
                  <span className={unit.livestock_type ? ' ml-2' : ''}>Start: {unit.herd_start_count} head</span>
                )}
              </div>
              <div className="mt-1">
                <Badge variant={unit.status === 'ACTIVE' ? 'success' : 'neutral'}>
                  {unit.status === 'ACTIVE' ? 'Active' : 'Closed'}
                </Badge>
              </div>
              <div className="mt-4 flex flex-wrap gap-2">
                <Link to={`/app/livestock/${unit.id}`} className="text-sm text-[#1F6F5C] font-medium hover:underline">
                  View herd
                </Link>
                <Link
                  to={`/app/livestock/${unit.id}`}
                  className="text-sm text-gray-600 hover:underline"
                >
                  Add event
                </Link>
                <Link
                  to={`/app/crop-ops/activities/new?production_unit_id=${unit.id}`}
                  className="text-sm text-gray-600 hover:underline"
                >
                  Log feed/medicine cost
                </Link>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal
        isOpen={showNewModal}
        onClose={() => {
          setShowNewModal(false);
          setForm(initialLivestockForm);
        }}
        title="New Livestock Unit"
      >
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Name" required className="md:col-span-2">
            <input
              type="text"
              value={form.name}
              onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
              placeholder="e.g. Goat Herd A"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Livestock type">
            <input
              type="text"
              value={form.livestock_type ?? ''}
              onChange={(e) => setForm((p) => ({ ...p, livestock_type: e.target.value }))}
              placeholder="e.g. GOAT"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Herd start count">
            <input
              type="number"
              min={0}
              value={form.herd_start_count ?? ''}
              onChange={(e) =>
                setForm((p) => ({
                  ...p,
                  herd_start_count: e.target.value ? parseInt(e.target.value, 10) : null,
                }))
              }
              placeholder="0"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Start date" required>
            <input
              type="date"
              value={form.start_date}
              onChange={(e) => setForm((p) => ({ ...p, start_date: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Notes (optional)" className="md:col-span-2">
            <textarea
              value={form.notes ?? ''}
              onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value || null }))}
              rows={2}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
        </div>
        <div className="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
          <button
            type="button"
            onClick={() => {
              setShowNewModal(false);
              setForm(initialLivestockForm);
            }}
            className="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleCreate}
            disabled={createMutation.isPending || !form.name?.trim()}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {createMutation.isPending ? 'Creating...' : 'Create'}
          </button>
        </div>
      </Modal>
    </div>
  );
}
