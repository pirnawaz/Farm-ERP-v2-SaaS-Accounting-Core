import { useState } from 'react';
import {
  useRateCardsQuery,
  useCreateRateCard,
  useUpdateRateCard,
  useMachinesQuery,
} from '../../hooks/useMachinery';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import type { MachineRateCard, CreateMachineRateCardPayload, UpdateMachineRateCardPayload } from '../../types';

export default function RateCardsPage() {
  const { data: rateCards, isLoading } = useRateCardsQuery();
  const { data: machines } = useMachinesQuery();
  const createRC = useCreateRateCard();
  const updateRC = useUpdateRateCard();
  const { hasRole } = useRole();
  const { formatCurrency, formatDate } = useFormatting();
  const [showModal, setShowModal] = useState(false);
  const [editingRateCard, setEditingRateCard] = useState<MachineRateCard | null>(null);
  const [form, setForm] = useState<CreateMachineRateCardPayload>({
    applies_to_mode: 'MACHINE',
    machine_id: null,
    machine_type: null,
    effective_from: new Date().toISOString().split('T')[0],
    effective_to: null,
    rate_unit: 'HOUR',
    pricing_model: 'FIXED',
    base_rate: 0,
    cost_plus_percent: null,
    includes_fuel: true,
    includes_operator: true,
    includes_maintenance: true,
    is_active: true,
  });

  const cols: Column<MachineRateCard>[] = [
    { 
      header: 'Applies To', 
      accessor: (r) => r.applies_to_mode === 'MACHINE' 
        ? (r.machine?.name || r.machine_id || 'N/A')
        : (r.machine_type || 'N/A')
    },
    { header: 'Rate Unit', accessor: 'rate_unit' },
    { header: 'Pricing Model', accessor: 'pricing_model' },
    { 
      header: 'Base Rate', 
      accessor: (r) => formatCurrency(parseFloat(r.base_rate))
    },
    { 
      header: 'Cost Plus %', 
      accessor: (r) => r.cost_plus_percent ? `${r.cost_plus_percent}%` : '-'
    },
    { 
      header: 'Effective From', 
      accessor: (r) => formatDate(r.effective_from)
    },
    { 
      header: 'Effective To', 
      accessor: (r) => r.effective_to ? formatDate(r.effective_to) : 'Open'
    },
    { header: 'Active', accessor: (r) => (r.is_active ? 'Yes' : 'No') },
    {
      header: 'Actions',
      accessor: (r) => (
        <button
          onClick={(e) => {
            e.stopPropagation();
            setEditingRateCard(r);
            setForm({
              applies_to_mode: r.applies_to_mode,
              machine_id: r.machine_id || null,
              machine_type: r.machine_type || null,
              effective_from: r.effective_from,
              effective_to: r.effective_to || null,
              rate_unit: r.rate_unit,
              pricing_model: r.pricing_model,
              base_rate: parseFloat(r.base_rate),
              cost_plus_percent: r.cost_plus_percent ? parseFloat(r.cost_plus_percent) : null,
              includes_fuel: r.includes_fuel,
              includes_operator: r.includes_operator,
              includes_maintenance: r.includes_maintenance,
              is_active: r.is_active,
            });
            setShowModal(true);
          }}
          className="px-3 py-1 text-sm text-[#1F6F5C] hover:text-[#1a5a4a]"
        >
          Edit
        </button>
      ),
    },
  ];

  const handleCreate = async () => {
    if (!form.effective_from || !form.base_rate || form.base_rate <= 0) return;
    if (form.applies_to_mode === 'MACHINE' && !form.machine_id) return;
    if (form.applies_to_mode === 'MACHINE_TYPE' && !form.machine_type) return;
    if (form.pricing_model === 'COST_PLUS' && (!form.cost_plus_percent || form.cost_plus_percent <= 0)) return;
    
    await createRC.mutateAsync(form);
    setShowModal(false);
    resetForm();
  };

  const handleUpdate = async () => {
    if (!editingRateCard || !form.effective_from || !form.base_rate || form.base_rate <= 0) return;
    if (form.applies_to_mode === 'MACHINE' && !form.machine_id) return;
    if (form.applies_to_mode === 'MACHINE_TYPE' && !form.machine_type) return;
    if (form.pricing_model === 'COST_PLUS' && (!form.cost_plus_percent || form.cost_plus_percent <= 0)) return;
    
    const payload: UpdateMachineRateCardPayload = {
      applies_to_mode: form.applies_to_mode,
      machine_id: form.machine_id,
      machine_type: form.machine_type,
      effective_from: form.effective_from,
      effective_to: form.effective_to,
      rate_unit: form.rate_unit,
      pricing_model: form.pricing_model,
      base_rate: form.base_rate,
      cost_plus_percent: form.cost_plus_percent,
      includes_fuel: form.includes_fuel,
      includes_operator: form.includes_operator,
      includes_maintenance: form.includes_maintenance,
      is_active: form.is_active,
    };
    await updateRC.mutateAsync({ id: editingRateCard.id, payload });
    setShowModal(false);
    setEditingRateCard(null);
    resetForm();
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingRateCard(null);
    resetForm();
  };

  const resetForm = () => {
    setForm({
      applies_to_mode: 'MACHINE',
      machine_id: null,
      machine_type: null,
      effective_from: new Date().toISOString().split('T')[0],
      effective_to: null,
      rate_unit: 'HOUR',
      pricing_model: 'FIXED',
      base_rate: 0,
      cost_plus_percent: null,
      includes_fuel: true,
      includes_operator: true,
      includes_maintenance: true,
      is_active: true,
    });
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Rate Cards"
        backTo="/app/machinery"
        breadcrumbs={[{ label: 'Machinery', to: '/app/machinery' }, { label: 'Rate Cards' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Rate Card</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={rateCards || []} columns={cols} emptyMessage="No rate cards. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={handleCloseModal} title={editingRateCard ? 'Edit Rate Card' : 'New Rate Card'}>
        <div className="space-y-4">
          <FormField label="Applies To Mode" required>
            <select
              value={form.applies_to_mode}
              onChange={e => {
                const mode = e.target.value as 'MACHINE' | 'MACHINE_TYPE';
                setForm(f => ({ 
                  ...f, 
                  applies_to_mode: mode,
                  machine_id: mode === 'MACHINE' ? f.machine_id : null,
                  machine_type: mode === 'MACHINE_TYPE' ? f.machine_type : null,
                }));
              }}
              className="w-full px-3 py-2 border rounded"
            >
              <option value="MACHINE">MACHINE</option>
              <option value="MACHINE_TYPE">MACHINE_TYPE</option>
            </select>
          </FormField>
          
          {form.applies_to_mode === 'MACHINE' ? (
            <FormField label="Machine" required>
              <select
                value={form.machine_id || ''}
                onChange={e => setForm(f => ({ ...f, machine_id: e.target.value || null }))}
                className="w-full px-3 py-2 border rounded"
              >
                <option value="">Select machine</option>
                {machines?.map(m => (
                  <option key={m.id} value={m.id}>{m.code} - {m.name}</option>
                ))}
              </select>
            </FormField>
          ) : (
            <FormField label="Machine Type" required>
              <input
                value={form.machine_type || ''}
                onChange={e => setForm(f => ({ ...f, machine_type: e.target.value || null }))}
                className="w-full px-3 py-2 border rounded"
                placeholder="e.g., Tractor, Harvester"
              />
            </FormField>
          )}

          <FormField label="Effective From" required>
            <input
              type="date"
              value={form.effective_from}
              onChange={e => setForm(f => ({ ...f, effective_from: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
            />
          </FormField>

          <FormField label="Effective To">
            <input
              type="date"
              value={form.effective_to || ''}
              onChange={e => setForm(f => ({ ...f, effective_to: e.target.value || null }))}
              className="w-full px-3 py-2 border rounded"
              min={form.effective_from}
            />
          </FormField>

          <FormField label="Rate Unit" required>
            <select
              value={form.rate_unit}
              onChange={e => setForm(f => ({ ...f, rate_unit: e.target.value as 'HOUR' | 'KM' | 'JOB' }))}
              className="w-full px-3 py-2 border rounded"
            >
              <option value="HOUR">HOUR</option>
              <option value="KM">KM</option>
              <option value="JOB">JOB</option>
            </select>
          </FormField>

          <FormField label="Pricing Model" required>
            <select
              value={form.pricing_model}
              onChange={e => setForm(f => ({ ...f, pricing_model: e.target.value as 'FIXED' | 'COST_PLUS' }))}
              className="w-full px-3 py-2 border rounded"
            >
              <option value="FIXED">FIXED</option>
              <option value="COST_PLUS">COST_PLUS</option>
            </select>
          </FormField>

          <FormField label="Base Rate" required>
            <input
              type="number"
              step="0.01"
              min="0"
              value={form.base_rate || ''}
              onChange={e => setForm(f => ({ ...f, base_rate: e.target.value ? parseFloat(e.target.value) : 0 }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="0.00"
            />
          </FormField>

          {form.pricing_model === 'COST_PLUS' && (
            <FormField label="Cost Plus Percent" required>
              <input
                type="number"
                step="0.01"
                min="0"
                max="100"
                value={form.cost_plus_percent || ''}
                onChange={e => setForm(f => ({ ...f, cost_plus_percent: e.target.value ? parseFloat(e.target.value) : null }))}
                className="w-full px-3 py-2 border rounded"
                placeholder="0.00"
              />
            </FormField>
          )}

          <FormField label="Includes Fuel">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.includes_fuel}
                onChange={e => setForm(f => ({ ...f, includes_fuel: e.target.checked }))}
              />
              <span>Includes Fuel</span>
            </label>
          </FormField>

          <FormField label="Includes Operator">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.includes_operator}
                onChange={e => setForm(f => ({ ...f, includes_operator: e.target.checked }))}
              />
              <span>Includes Operator</span>
            </label>
          </FormField>

          <FormField label="Includes Maintenance">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.includes_maintenance}
                onChange={e => setForm(f => ({ ...f, includes_maintenance: e.target.checked }))}
              />
              <span>Includes Maintenance</span>
            </label>
          </FormField>

          <FormField label="Active">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))}
              />
              <span>Active</span>
            </label>
          </FormField>

          <div className="flex gap-2 pt-4">
            <button onClick={handleCloseModal} className="px-4 py-2 border rounded">Cancel</button>
            {editingRateCard ? (
              <button
                onClick={handleUpdate}
                disabled={
                  !form.effective_from || 
                  !form.base_rate || 
                  form.base_rate <= 0 ||
                  (form.applies_to_mode === 'MACHINE' && !form.machine_id) ||
                  (form.applies_to_mode === 'MACHINE_TYPE' && !form.machine_type) ||
                  (form.pricing_model === 'COST_PLUS' && (!form.cost_plus_percent || form.cost_plus_percent <= 0)) ||
                  updateRC.isPending
                }
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {updateRC.isPending ? 'Updating...' : 'Update'}
              </button>
            ) : (
              <button
                onClick={handleCreate}
                disabled={
                  !form.effective_from || 
                  !form.base_rate || 
                  form.base_rate <= 0 ||
                  (form.applies_to_mode === 'MACHINE' && !form.machine_id) ||
                  (form.applies_to_mode === 'MACHINE_TYPE' && !form.machine_type) ||
                  (form.pricing_model === 'COST_PLUS' && (!form.cost_plus_percent || form.cost_plus_percent <= 0)) ||
                  createRC.isPending
                }
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {createRC.isPending ? 'Creating...' : 'Create'}
              </button>
            )}
          </div>
        </div>
      </Modal>
    </div>
  );
}
