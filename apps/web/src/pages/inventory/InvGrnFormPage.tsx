import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateGRN } from '../../hooks/useInventory';
import { useParties } from '../../hooks/useParties';
import { useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { PageContainer } from '../../components/PageContainer';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { generateDocNo, getStored, setStored, formStorageKeys } from '../../utils/formDefaults';
import { term } from '../../config/terminology';
import { FormActions, FormCard, FormSection } from '../../components/FormLayout';
import type { CreateInvGrnPayload } from '../../types';

type Line = { item_id: string; qty: string; unit_cost: string };

export default function InvGrnFormPage() {
  const navigate = useNavigate();
  const createM = useCreateGRN();
  const { data: parties } = useParties();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();
  const { formatMoney } = useFormatting();

  const [doc_no, setDocNo] = useState('');
  const [supplier_party_id, setSupplierPartyId] = useState('');
  const [store_id, setStoreId] = useState('');
  const [doc_date, setDocDate] = useState(new Date().toISOString().split('T')[0]);
  const [lines, setLines] = useState<Line[]>([{ item_id: '', qty: '', unit_cost: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  useEffect(() => {
    if (!doc_no) setDocNo(generateDocNo('GRN'));
  }, []);
  useEffect(() => {
    const stored = getStored<string>(formStorageKeys.last_store_id);
    if (stored && stores?.some((s) => s.id === stored) && !store_id) setStoreId(stored);
  }, [stores]);
  useEffect(() => {
    const stored = getStored<string>(formStorageKeys.last_supplier_party_id);
    if (stored && parties?.some((p) => p.id === stored) && !supplier_party_id) setSupplierPartyId(stored);
  }, [parties]);
  useEffect(() => {
    if (store_id) setStored(formStorageKeys.last_store_id, store_id);
  }, [store_id]);
  useEffect(() => {
    if (supplier_party_id) setStored(formStorageKeys.last_supplier_party_id, supplier_party_id);
  }, [supplier_party_id]);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty: '', unit_cost: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const lineTotals = lines.map((l) => (parseFloat(l.qty) || 0) * (parseFloat(l.unit_cost) || 0));
  const total = lineTotals.reduce((a, b) => a + b, 0);

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!store_id) e.store_id = 'Store is required';
    if (!doc_date) e.doc_date = 'Doc date is required';
    const validLines = lines.filter((l) => l.item_id && parseFloat(l.qty) > 0 && parseFloat(l.unit_cost) >= 0);
    if (validLines.length === 0) e.lines = 'At least one line with item, qty > 0, unit cost >= 0 is required';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async () => {
    if (!validate() || !canEdit) return;
    const validLines = lines
      .filter((l) => l.item_id && parseFloat(l.qty) > 0 && parseFloat(l.unit_cost) >= 0)
      .map((l) => ({ item_id: l.item_id, qty: parseFloat(l.qty), unit_cost: parseFloat(l.unit_cost) }));
    const finalDocNo = doc_no.trim() || generateDocNo('GRN');
    const payload: CreateInvGrnPayload = {
      doc_no: finalDocNo,
      supplier_party_id: supplier_party_id || undefined,
      store_id,
      doc_date,
      lines: validLines,
    };
    const grn = await createM.mutateAsync(payload);
    navigate(`/app/inventory/grns/${grn.id}`);
  };

  return (
    <PageContainer width="form" className="space-y-6">
      <PageHeader
        title="New Goods Received"
        backTo="/app/inventory/grns"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Inventory Overview', to: '/app/inventory' },
          { label: term('grn'), to: '/app/inventory/grns' },
          { label: 'New Goods Received' },
        ]}
      />

      <FormCard>
        <FormSection title="Header">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <FormField label="Doc No">
              <input
                value={doc_no}
                onChange={(e) => setDocNo(e.target.value)}
                disabled={!canEdit}
                placeholder={generateDocNo('GRN')}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-50"
              />
            </FormField>
            <FormField label="Doc Date" required error={errors.doc_date}>
              <input
                type="date"
                value={doc_date}
                onChange={(e) => setDocDate(e.target.value)}
                disabled={!canEdit}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-50"
              />
            </FormField>
            <FormField label="Store" required error={errors.store_id}>
              <select
                value={store_id}
                onChange={(e) => setStoreId(e.target.value)}
                disabled={!canEdit}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-50"
              >
                <option value="">Select store</option>
                {stores?.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </FormField>
            <FormField label="Supplier (optional)">
              <select
                value={supplier_party_id}
                onChange={(e) => setSupplierPartyId(e.target.value)}
                disabled={!canEdit}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-50"
              >
                <option value="">—</option>
                {parties?.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </FormField>
          </div>
        </FormSection>

        <FormSection
          title="Lines"
          className="pt-2"
        >
          <div className="flex justify-between items-center">
            <div />
            {canEdit && (
              <button type="button" onClick={addLine} className="text-sm font-medium text-[#1F6F5C] hover:underline">
                + Add line
              </button>
            )}
          </div>
          {errors.lines && <p className="text-sm text-red-600">{errors.lines}</p>}
          <div className="space-y-3">
            {lines.map((line, i) => (
              <div key={i} className="border border-gray-200 rounded-lg p-4 bg-gray-50/50 space-y-3">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <FormField label="Item">
                    <select
                      value={line.item_id}
                      onChange={(e) => updateLine(i, { item_id: e.target.value })}
                      disabled={!canEdit}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-50"
                    >
                      <option value="">Select item</option>
                      {items?.map((it) => (
                        <option key={it.id} value={it.id}>{it.name} ({it.uom?.code})</option>
                      ))}
                    </select>
                  </FormField>
                  <FormField label="Qty">
                    <input
                      type="number"
                      step="any"
                      min="0"
                      value={line.qty}
                      onChange={(e) => updateLine(i, { qty: e.target.value })}
                      disabled={!canEdit}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-50"
                    />
                  </FormField>
                  <FormField label="Unit cost">
                    <input
                      type="number"
                      step="any"
                      min="0"
                      value={line.unit_cost}
                      onChange={(e) => updateLine(i, { unit_cost: e.target.value })}
                      disabled={!canEdit}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-50"
                    />
                  </FormField>
                  <FormField label="Line total">
                    <span className="block px-3 py-2 text-sm tabular-nums">
                      {formatMoney((parseFloat(line.qty) || 0) * (parseFloat(line.unit_cost) || 0))}
                    </span>
                  </FormField>
                </div>
                {canEdit && (
                  <div className="flex justify-end">
                    <button type="button" onClick={() => removeLine(i)} className="text-sm text-red-600 hover:underline">
                      Remove
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
          <p className="font-medium text-gray-700">Total: <span className="tabular-nums">{formatMoney(total)}</span></p>
        </FormSection>

        {canEdit && (
          <FormActions>
            <button
              type="button"
              onClick={() => navigate('/app/inventory/grns')}
              className="w-full sm:w-auto px-4 py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handleSubmit}
              disabled={createM.isPending}
              className="w-full sm:w-auto px-4 py-2.5 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {createM.isPending ? 'Creating...' : 'Create'}
            </button>
          </FormActions>
        )}
      </FormCard>
    </PageContainer>
  );
}
