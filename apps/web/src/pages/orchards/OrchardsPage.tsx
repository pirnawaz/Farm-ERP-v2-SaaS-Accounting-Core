import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useProductionUnits, useCreateProductionUnit } from '../../hooks/useProductionUnits';
import { PageHeader } from '../../components/PageHeader';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import toast from 'react-hot-toast';
import type { ProductionUnit, CreateProductionUnitPayload } from '../../types';

const currentYear = new Date().getFullYear();
const lastYear = currentYear - 1;

type OrchardFormState = CreateProductionUnitPayload & { orchard_crop?: string; planting_year?: number | null; area_acres?: string; tree_count?: number | null };

const initialOrchardForm: OrchardFormState = {
  name: '',
  type: 'LONG_CYCLE',
  start_date: new Date().toISOString().split('T')[0],
  end_date: null,
  notes: null,
  category: 'ORCHARD',
  orchard_crop: '',
  planting_year: currentYear,
  area_acres: '',
  tree_count: null,
};

export default function OrchardsPage() {
  const { data: orchardUnits, isLoading } = useProductionUnits({ category: 'ORCHARD' });
  const createMutation = useCreateProductionUnit();
  const [showNewModal, setShowNewModal] = useState(false);
  const [form, setForm] = useState<OrchardFormState>(initialOrchardForm);

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
        category: 'ORCHARD',
        orchard_crop: form.orchard_crop?.trim() || undefined,
        planting_year: form.planting_year ?? undefined,
        area_acres: form.area_acres ? parseFloat(form.area_acres) : undefined,
        tree_count: form.tree_count ?? undefined,
      });
      toast.success('Orchard unit created');
      setShowNewModal(false);
      setForm(initialOrchardForm);
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string }; status?: number }; message?: string };
      toast.error(err?.response?.data?.message ?? err?.message ?? 'Failed to create orchard unit');
    }
  };

  const orchards = orchardUnits ?? [];

  return (
    <div className="space-y-6" data-testid="orchards-page">
      <PageHeader
        title="Orchards"
        backTo="/app/dashboard"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Orchards' },
        ]}
        right={
          <button
            type="button"
            data-testid="new-orchard-unit"
            onClick={() => setShowNewModal(true)}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Orchard Unit
          </button>
        }
      />
      <p className="text-gray-600">Orchard production units: track costs, revenue and activities by orchard.</p>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : orchards.length === 0 ? (
        <div className="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center text-gray-600">
          No orchard units yet. Create one to get started.
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {orchards.map((unit: ProductionUnit) => (
            <div
              key={unit.id}
              className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm flex flex-col"
            >
              <div className="font-semibold text-gray-900">{unit.name}</div>
              <div className="mt-1 text-sm text-gray-600">
                {unit.orchard_crop && <span>Crop: {unit.orchard_crop}</span>}
                {unit.planting_year != null && (
                  <span className={unit.orchard_crop ? ' ml-2' : ''}>Planted: {unit.planting_year}</span>
                )}
              </div>
              <div className="mt-1 text-sm text-gray-500">
                {unit.area_acres != null && unit.area_acres !== '' && <span>{unit.area_acres} acres</span>}
                {unit.tree_count != null && (
                  <span className={unit.area_acres ? ' · ' : ''}>{unit.tree_count} trees</span>
                )}
              </div>
              <div className="mt-1">
                <span
                  className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${
                    unit.status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'
                  }`}
                >
                  {unit.status}
                </span>
              </div>
              <div className="mt-4 flex flex-wrap gap-2">
                <Link
                  to={`/app/orchards/${unit.id}`}
                  className="text-sm text-[#1F6F5C] font-medium hover:underline"
                >
                  View this year
                </Link>
                <Link
                  to={`/app/orchards/${unit.id}?year=${lastYear}`}
                  className="text-sm text-gray-600 hover:underline"
                >
                  View last year
                </Link>
                <Link
                  to={`/app/harvests/new?production_unit_id=${unit.id}`}
                  className="text-sm text-[#1F6F5C] font-medium hover:underline"
                >
                  Add harvest
                </Link>
                <Link
                  to={`/app/labour/work-logs/new?production_unit_id=${unit.id}`}
                  className="text-sm text-gray-600 hover:underline"
                >
                  Log work
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
          setForm(initialOrchardForm);
        }}
        title="New Orchard Unit"
      >
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Name" required className="md:col-span-2">
            <input
              type="text"
              value={form.name}
              onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
              placeholder="e.g. North Mango Block"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Orchard crop">
            <input
              type="text"
              value={form.orchard_crop ?? ''}
              onChange={(e) => setForm((p) => ({ ...p, orchard_crop: e.target.value }))}
              placeholder="e.g. Mango, Lemon, Phalsa"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Planting year">
            <input
              type="number"
              min={1900}
              max={2100}
              value={form.planting_year ?? ''}
              onChange={(e) =>
                setForm((p) => ({ ...p, planting_year: e.target.value ? parseInt(e.target.value, 10) : null }))
              }
              placeholder={String(currentYear)}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Area (acres)">
            <input
              type="number"
              min={0}
              step={0.01}
              value={form.area_acres ?? ''}
              onChange={(e) => setForm((p) => ({ ...p, area_acres: e.target.value }))}
              placeholder="0"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Tree count">
            <input
              type="number"
              min={0}
              value={form.tree_count ?? ''}
              onChange={(e) =>
                setForm((p) => ({ ...p, tree_count: e.target.value ? parseInt(e.target.value, 10) : null }))
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
              setForm(initialOrchardForm);
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
