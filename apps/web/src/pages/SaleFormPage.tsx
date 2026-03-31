import { useState, useEffect, useCallback } from 'react';
import { useParams, Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useSale, useCreateSale, useUpdateSale } from '../hooks/useSales';
import { useParties } from '../hooks/useParties';
import { useProjects } from '../hooks/useProjects';
import { useCropCycles } from '../hooks/useCropCycles';
import { useProductionUnits } from '../hooks/useProductionUnits';
import { useInventoryStores, useInventoryItems } from '../hooks/useInventory';
import { useTenant } from '../hooks/useTenant';
import { useFormAutosave } from '../hooks/useFormAutosave';
import { FormField } from '../components/FormField';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { PageHeader } from '../components/PageHeader';
import { useRole } from '../hooks/useRole';
import { saleSchema } from '../validation/saleSchema';
import { getLastSubmit, setLastSubmit } from '../utils/formDefaults';
import toast from 'react-hot-toast';
import { term } from '../config/terminology';
import type { CreateSalePayload, SaleLine } from '../types';

type SaleLineFormRow = Omit<SaleLine, 'id' | 'sale_id' | 'line_total'>;
type SaleSnapshot = Omit<CreateSalePayload, 'sale_lines'> & {
  sale_lines?: SaleLineFormRow[];
  saleLinesForm: SaleLineFormRow[];
};

export default function SaleFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const isEdit = !!id;
  const { tenantId } = useTenant();
  const { data: sale, isLoading } = useSale(id || '');
  const createMutation = useCreateSale();
  const updateMutation = useUpdateSale();
  const { data: parties } = useParties();
  const { data: projects } = useProjects();
  const { data: cropCycles } = useCropCycles();
  const { data: productionUnits } = useProductionUnits();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();
  
  // Get query params for prefill
  const prefilledBuyerPartyId = searchParams.get('buyerPartyId');
  const prefilledProductionUnitId = searchParams.get('production_unit_id') || '';

  const [formData, setFormData] = useState<CreateSalePayload>({
    buyer_party_id: prefilledBuyerPartyId || '',
    project_id: '',
    crop_cycle_id: '',
    production_unit_id: prefilledProductionUnitId,
    amount: '',
    posting_date: new Date().toISOString().split('T')[0],
    sale_no: '',
    sale_date: '',
    due_date: '',
    notes: '',
    sale_lines: [],
  });

  const [saleLines, setSaleLines] = useState<Omit<SaleLine, 'id' | 'sale_id' | 'line_total'>[]>([
    { inventory_item_id: '', store_id: '', quantity: '', unit_price: '' }
  ]);

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (sale && isEdit) {
      setFormData({
        buyer_party_id: sale.buyer_party_id,
        project_id: sale.project_id || '',
        crop_cycle_id: sale.crop_cycle_id || '',
        production_unit_id: (sale as { production_unit_id?: string }).production_unit_id || '',
        amount: sale.amount,
        posting_date: sale.posting_date,
        sale_no: sale.sale_no || '',
        sale_date: sale.sale_date || sale.posting_date,
        due_date: sale.due_date || sale.posting_date,
        notes: sale.notes || '',
        sale_lines: sale.lines || [],
      });
      if (sale.lines && sale.lines.length > 0) {
        setSaleLines(sale.lines.map(line => ({
          inventory_item_id: line.inventory_item_id,
          store_id: line.store_id || '',
          quantity: line.quantity,
          unit_price: line.unit_price,
          uom: line.uom,
        })));
      }
    } else if (!isEdit && prefilledBuyerPartyId) {
      setFormData((prev) => ({
        ...prev,
        buyer_party_id: prefilledBuyerPartyId || prev.buyer_party_id,
      }));
    }
  }, [sale, isEdit, prefilledBuyerPartyId]);

  // Calculate total amount from sale lines
  useEffect(() => {
    const total = saleLines.reduce((sum, line) => {
      const qty = parseFloat(line.quantity) || 0;
      const price = parseFloat(line.unit_price) || 0;
      return sum + (qty * price);
    }, 0);
    setFormData(prev => ({ ...prev, amount: total.toFixed(2) }));
  }, [saleLines]);

  const addSaleLine = () => {
    setSaleLines([...saleLines, { inventory_item_id: '', store_id: '', quantity: '', unit_price: '' }]);
  };

  const removeSaleLine = (index: number) => {
    setSaleLines(saleLines.filter((_, i) => i !== index));
  };

  const updateSaleLine = (index: number, field: Partial<Omit<SaleLine, 'id' | 'sale_id' | 'line_total'>>) => {
    setSaleLines(saleLines.map((line, i) => i === index ? { ...line, ...field } : line));
  };

  const getSnapshot = useCallback((): SaleSnapshot => {
    const valid = saleLines.filter(
      (l) => l.inventory_item_id && l.store_id && parseFloat(l.quantity) > 0 && parseFloat(l.unit_price) > 0
    );
    return {
      ...formData,
      sale_lines: valid.map((l) => ({
        inventory_item_id: l.inventory_item_id,
        store_id: l.store_id,
        quantity: l.quantity,
        unit_price: l.unit_price,
        uom: l.uom,
      })),
      saleLinesForm: [...saleLines],
    };
  }, [formData, saleLines]);

  const applySnapshot = useCallback((data: SaleSnapshot) => {
    setFormData({
      buyer_party_id: data.buyer_party_id ?? '',
      project_id: data.project_id ?? '',
      crop_cycle_id: data.crop_cycle_id ?? '',
      production_unit_id: data.production_unit_id ?? '',
      amount: data.amount ?? '',
      posting_date: data.posting_date ?? '',
      sale_no: data.sale_no ?? '',
      sale_date: data.sale_date ?? '',
      due_date: data.due_date ?? '',
      notes: data.notes ?? '',
      sale_lines: [], // computed from saleLines in effect
    });
    setSaleLines(
      data.saleLinesForm?.length
        ? data.saleLinesForm
        : [{ inventory_item_id: '', store_id: '', quantity: '', unit_price: '' }]
    );
  }, []);

  const { hasDraft, restore, discard, clearDraft } = useFormAutosave<SaleSnapshot>({
    formId: 'sale',
    tenantId: tenantId || '',
    context: formData.crop_cycle_id ? { crop_cycle_id: formData.crop_cycle_id } : undefined,
    getSnapshot,
    applySnapshot,
    debounceMs: 4000,
    disabled: !tenantId || isEdit,
  });

  const handleUseLast = () => {
    const last = getLastSubmit<SaleSnapshot>(tenantId || '', 'sale');
    if (!last) return;
    applySnapshot(last);
  };
  const hasLast = !isEdit && !!tenantId && getLastSubmit(tenantId, 'sale') != null;

  const handleSubmit = async () => {
    // Prepare sale lines
    const validLines = saleLines
      .filter(line => line.inventory_item_id && line.store_id && 
        parseFloat(line.quantity) > 0 && parseFloat(line.unit_price) > 0)
      .map(line => {
        const qty = parseFloat(line.quantity);
        const price = parseFloat(line.unit_price);
        return {
          inventory_item_id: line.inventory_item_id,
          store_id: line.store_id,
          quantity: line.quantity,
          unit_price: line.unit_price,
          uom: line.uom,
          line_total: (qty * price).toFixed(2),
        };
      });

    const formPayload = {
      ...formData,
      project_id: formData.project_id || undefined,
      crop_cycle_id: formData.crop_cycle_id || undefined,
      production_unit_id: formData.production_unit_id || undefined,
      notes: formData.notes || undefined,
      sale_lines: validLines.length > 0 ? validLines : [],
    };

    // Validate with zod
    try {
      saleSchema.parse(formPayload);
      setErrors({});
    } catch (error: unknown) {
      const z = error as { errors?: Array<{ path: string[]; message: string }> };
      if (z.errors) {
        const zodErrors: Record<string, string> = {};
        z.errors.forEach((err: { path: string[]; message: string }) => {
          const path = err.path.join('.');
          zodErrors[path] = err.message;
        });
        setErrors(zodErrors);
        toast.error('Please fix validation errors');
        return;
      }
    }

    try {
      const payload: CreateSalePayload = {
        ...formPayload,
        sale_lines: validLines.length > 0 ? validLines : undefined,
      };

      if (isEdit && id) {
        await updateMutation.mutateAsync({ id, payload });
      } else {
        await createMutation.mutateAsync(payload);
        setLastSubmit(tenantId || '', 'sale', getSnapshot());
        clearDraft();
      }
      navigate('/app/sales');
    } catch (error: any) {
      // Error handled by mutation
    }
  };

  if (isLoading && isEdit) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (isEdit && sale?.status !== 'DRAFT') {
    return (
      <div>
        <p className="text-red-600">This sale cannot be edited because it is not in DRAFT status.</p>
        <Link to="/app/sales" className="text-[#1F6F5C] hover:text-[#1a5a4a]">
          Back to Sales
        </Link>
      </div>
    );
  }

  return (
    <div className="max-w-2xl mx-auto">
      <PageHeader
        title={isEdit ? 'Edit Sale' : 'New Sale'}
        backTo="/app/sales"
        breadcrumbs={[
          { label: 'Sales & Money', to: '/app/sales' },
          { label: 'Sales', to: '/app/sales' },
          { label: isEdit ? 'Edit Sale' : 'New Sale' },
        ]}
      />

      {!isEdit && hasDraft && (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex flex-wrap items-center gap-2">
          <span>Restore draft?</span>
          <button type="button" onClick={restore} className="font-medium text-[#1F6F5C] hover:underline">Restore</button>
          <span>|</span>
          <button type="button" onClick={discard} className="font-medium text-gray-600 hover:underline">Discard</button>
        </div>
      )}
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-6 space-y-6">
        {hasLast && (
          <div className="flex justify-end">
            <button type="button" onClick={handleUseLast} className="text-sm font-medium text-[#1F6F5C] hover:underline">
              Use last values
            </button>
          </div>
        )}
        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Party & amount</h2>
          <FormField label="Buyer Party" required error={errors.buyer_party_id}>
            <select
              value={formData.buyer_party_id}
              onChange={(e) => setFormData({ ...formData, buyer_party_id: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="">Select a buyer</option>
              {parties?.map((party) => (
                <option key={party.id} value={party.id}>
                  {party.name}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Amount" required error={errors.amount}>
            <input
              type="number"
              step="0.01"
              min="0.01"
              value={formData.amount}
              onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
              disabled={!canEdit || saleLines.length > 0}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
            {saleLines.length > 0 && (
              <p className="text-xs text-gray-500 mt-1">Amount is calculated from sale lines</p>
            )}
          </FormField>
        </section>

          <section className="space-y-4">
            <div className="flex justify-between items-center">
              <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Sale Lines *</h2>
              {canEdit && (
                <button
                  onClick={addSaleLine}
                  className="text-sm text-[#1F6F5C] hover:underline"
                  type="button"
                >
                  + Add Line
                </button>
              )}
            </div>
            {(items && items.length === 0) || (stores && stores.length === 0) ? (
              <div className="mb-3 p-3 bg-amber-50 border border-amber-200 rounded-md text-sm text-amber-800">
                {(items?.length === 0) && (stores?.length === 0)
? `No ${term('inventoryItem').toLowerCase()} or stores yet. Create them in `
                    : (items?.length === 0)
                    ? `No ${term('inventoryItem').toLowerCase()} yet. Create them in `
                    : 'No stores yet. Create them in '}
                {(items?.length === 0) && (
                  <Link to="/app/inventory/items" className="text-[#1F6F5C] font-medium hover:underline">Inventory → {term('inventoryItem')}</Link>
                )}
                {(items?.length === 0) && (stores?.length === 0) && ' and '}
                {(stores?.length === 0) && (
                  <Link to="/app/inventory/stores" className="text-[#1F6F5C] font-medium hover:underline">Inventory → Stores</Link>
                )}
                {' first.'}
              </div>
            ) : null}
            {errors.sale_lines && (
              <p className="text-red-600 text-sm">{errors.sale_lines}</p>
            )}
            <div className="space-y-3">
              {saleLines.map((line, idx) => (
                <div key={idx} className="border border-gray-200 rounded-lg p-4 bg-gray-50/50 space-y-3">
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField label="Item *">
                      <select
                        value={line.inventory_item_id}
                        onChange={(e) => updateSaleLine(idx, { inventory_item_id: e.target.value })}
                        disabled={!canEdit}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-100"
                      >
                        <option value="">Item *</option>
                        {items?.map((i) => (
                          <option key={i.id} value={i.id}>{i.name}</option>
                        ))}
                      </select>
                    </FormField>
                    <FormField label="Store *">
                      <select
                        value={line.store_id}
                        onChange={(e) => updateSaleLine(idx, { store_id: e.target.value })}
                        disabled={!canEdit}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-100"
                      >
                        <option value="">Store *</option>
                        {stores?.map((s) => (
                          <option key={s.id} value={s.id}>{s.name}</option>
                        ))}
                      </select>
                    </FormField>
                    <FormField label="Qty *">
                      <input
                        type="number"
                        step="0.001"
                        min="0.001"
                        value={line.quantity}
                        onChange={(e) => updateSaleLine(idx, { quantity: e.target.value })}
                        disabled={!canEdit}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-100"
                        placeholder="Qty"
                      />
                    </FormField>
                    <FormField label="Unit price *">
                      <input
                        type="number"
                        step="0.01"
                        min="0.01"
                        value={line.unit_price}
                        onChange={(e) => updateSaleLine(idx, { unit_price: e.target.value })}
                        disabled={!canEdit}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-100"
                        placeholder="Price"
                      />
                    </FormField>
                    <FormField label="UOM">
                      <input
                        type="text"
                        value={line.uom || ''}
                        onChange={(e) => updateSaleLine(idx, { uom: e.target.value })}
                        disabled={!canEdit}
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-100"
                        placeholder="UOM"
                      />
                    </FormField>
                    <FormField label="Line total">
                      <span className="block px-3 py-2 text-sm text-gray-600 tabular-nums">
                        {(parseFloat(line.quantity) || 0) * (parseFloat(line.unit_price) || 0)}
                      </span>
                    </FormField>
                  </div>
                  {canEdit && (
                    <div className="flex justify-end">
                      <button
                        onClick={() => removeSaleLine(idx)}
                        className="text-sm text-red-600 hover:underline"
                        type="button"
                      >
                        Remove
                      </button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </section>

        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Dates</h2>
          <FormField label="Posting Date" required error={errors.posting_date}>
            <input
              type="date"
              value={formData.posting_date}
              onChange={(e) => {
                const newDate = e.target.value;
                setFormData({ 
                  ...formData, 
                  posting_date: newDate,
                  // Auto-set sale_date and due_date if not set
                  sale_date: formData.sale_date || newDate,
                  due_date: formData.due_date || newDate,
                });
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Sale Number (Optional)">
            <input
              type="text"
              value={formData.sale_no}
              onChange={(e) => setFormData({ ...formData, sale_no: e.target.value })}
              disabled={!canEdit}
              placeholder="Leave blank to auto-generate"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Sale Date (Optional)">
            <input
              type="date"
              value={formData.sale_date}
              onChange={(e) => setFormData({ ...formData, sale_date: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
            <p className="text-xs text-gray-500 mt-1">Defaults to posting date if not set</p>
          </FormField>

          <FormField label="Due Date (Optional)">
            <input
              type="date"
              value={formData.due_date}
              onChange={(e) => setFormData({ ...formData, due_date: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
            <p className="text-xs text-gray-500 mt-1">Used for AR ageing. Defaults to sale date if not set</p>
          </FormField>
        </section>

          <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Optional</h2>
          <FormField label={`${term('fieldCycle')} (Optional)`}>
            <select
              value={formData.project_id}
              onChange={(e) => setFormData({ ...formData, project_id: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="">None</option>
              {projects?.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.name}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Crop Cycle (Optional)">
            <select
              value={formData.crop_cycle_id}
              onChange={(e) => setFormData({ ...formData, crop_cycle_id: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="">None</option>
              {cropCycles?.map((cycle) => (
                <option key={cycle.id} value={cycle.id}>
                  {cycle.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Production Unit (Optional)">
            <select
              value={formData.production_unit_id ?? ''}
              onChange={(e) => setFormData({ ...formData, production_unit_id: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="">None</option>
              {productionUnits?.map((u) => (
                <option key={u.id} value={u.id}>{u.name}</option>
              ))}
            </select>
          </FormField>

          <FormField label="Notes">
            <textarea
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              disabled={!canEdit}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>
          </section>

          {canEdit && (
            <div className="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-4 border-t">
              <Link
                to="/app/sales"
                className="w-full sm:w-auto px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-center"
              >
                Cancel
              </Link>
              <button
                onClick={handleSubmit}
                disabled={createMutation.isPending || updateMutation.isPending}
                className="w-full sm:w-auto px-4 py-2.5 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {createMutation.isPending || updateMutation.isPending ? 'Saving...' : 'Save'}
              </button>
            </div>
          )}
      </div>
    </div>
  );
}
