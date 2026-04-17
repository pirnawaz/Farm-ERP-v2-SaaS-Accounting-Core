import { useState, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateAdjustment, useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { term } from '../../config/terminology';
import type { CreateInvAdjustmentPayload, InvAdjustmentReason } from '../../types';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';
import { getStored, setStored, formStorageKeys } from '../../utils/formDefaults';
import { useBlockUnsavedNavigation } from '../../hooks/useBlockUnsavedNavigation';

const REASONS: InvAdjustmentReason[] = ['LOSS', 'DAMAGE', 'COUNT_GAIN', 'COUNT_LOSS', 'OTHER'];

type Line = { item_id: string; qty_delta: string };

export default function InvAdjustmentFormPage() {
  const navigate = useNavigate();
  const createM = useCreateAdjustment();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();

  const [doc_no, setDocNo] = useState('');
  const [store_id, setStoreId] = useState('');
  const [reason, setReason] = useState<InvAdjustmentReason>('LOSS');
  const [notes, setNotes] = useState('');
  const [doc_date, setDocDate] = useState(new Date().toISOString().split('T')[0]);
  const [lines, setLines] = useState<Line[]>([{ item_id: '', qty_delta: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [touched, setTouched] = useState(false);
  const markTouched = () => setTouched(true);

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  useEffect(() => {
    if (!stores?.length || store_id) return;
    const stored = getStored<string>(formStorageKeys.last_store_id);
    if (stored && stores.some((s) => s.id === stored)) {
      setStoreId(stored);
      return;
    }
    if (stores.length === 1) setStoreId(stores[0].id);
  }, [stores, store_id]);

  useEffect(() => {
    if (store_id) setStored(formStorageKeys.last_store_id, store_id);
  }, [store_id]);

  useBlockUnsavedNavigation(touched && canEdit);

  const addLine = () => {
    markTouched();
    setLines((l) => [...l, { item_id: '', qty_delta: '' }]);
  };
  const removeLine = (i: number) => {
    markTouched();
    setLines((l) => l.filter((_, idx) => idx !== i));
  };
  const updateLine = (i: number, f: Partial<Line>) => {
    markTouched();
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));
  };

  const validLines = useMemo(
    () => lines.filter((l) => l.item_id && parseFloat(l.qty_delta) !== 0),
    [lines]
  );

  const canSaveDraft = Boolean(store_id && reason && doc_date && validLines.length > 0);

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!store_id) e.store_id = 'Store is required';
    if (!reason) e.reason = 'Reason is required';
    if (!doc_date) e.doc_date = 'Doc date is required';
    if (validLines.length === 0) e.lines = 'At least one line with item and qty_delta not zero is required';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const readinessItems = useMemo(
    () => [
      { ok: Boolean(store_id), label: 'Store selected' },
      { ok: Boolean(reason), label: 'Reason selected' },
      { ok: Boolean(doc_date), label: 'Document date set' },
      { ok: validLines.length > 0, label: 'At least one line with non-zero quantity change' },
    ],
    [store_id, reason, doc_date, validLines.length]
  );

  const handleSubmit = async () => {
    if (!canSaveDraft || !validate() || !canEdit) return;
    const payloadLines = validLines.map((l) => ({ item_id: l.item_id, qty_delta: parseFloat(l.qty_delta) }));
    const payload: CreateInvAdjustmentPayload = {
      ...(doc_no.trim() && { doc_no: doc_no.trim() }),
      store_id,
      reason,
      notes: notes.trim() || undefined,
      doc_date,
      lines: payloadLines,
    };
    const adj = await createM.mutateAsync(payload);
    navigate(`/app/inventory/adjustments/${adj.id}`);
  };

  return (
    <div className="space-y-6 pb-8">
      <PageHeader
        title="New stock adjustment"
        description="Correct on-hand balances for counts, loss, damage, or other one-off changes."
        helper="Use positive or negative line quantities. Saving creates a draft until you record to accounts on the next screen."
        backTo="/app/inventory/adjustments"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Inventory Overview', to: '/app/inventory' },
          { label: term('adjustment'), to: '/app/inventory/adjustments' },
          { label: 'New stock adjustment' },
        ]}
      />
      <div className="bg-white rounded-lg shadow p-6 space-y-6">
        <PrePostChecklist
          items={readinessItems}
          blockingHint={!canSaveDraft ? 'Complete required fields before saving this draft.' : undefined}
        />
        {createM.isError ? <OperatorErrorCallout error={formatOperatorError(createM.error)} /> : null}

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Doc No">
            <input
              value={doc_no}
              onChange={(e) => {
                markTouched();
                setDocNo(e.target.value);
              }}
              disabled={!canEdit}
              placeholder="Leave blank to auto-generate"
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            />
          </FormField>
          <FormField label="Doc Date" required error={errors.doc_date}>
            <input
              type="date"
              value={doc_date}
              onChange={(e) => {
                markTouched();
                setDocDate(e.target.value);
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            />
          </FormField>
          <FormField label="Store" required error={errors.store_id}>
            <select
              value={store_id}
              onChange={(e) => {
                markTouched();
                setStoreId(e.target.value);
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            >
              <option value="">Select store</option>
              {stores?.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Reason" required error={errors.reason}>
            <select
              value={reason}
              onChange={(e) => {
                markTouched();
                setReason(e.target.value as InvAdjustmentReason);
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            >
              {REASONS.map((r) => (
                <option key={r} value={r}>
                  {r}
                </option>
              ))}
            </select>
          </FormField>
          <div className="md:col-span-2">
            <FormField label="Notes">
              <textarea
                value={notes}
                onChange={(e) => {
                  markTouched();
                  setNotes(e.target.value);
                }}
                disabled={!canEdit}
                className="w-full px-3 py-2 border rounded"
                rows={2}
              />
            </FormField>
          </div>
        </div>
        <div className="space-y-3">
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium">Lines (qty_delta: + gain, - loss)</h3>
            {canEdit && (
              <button type="button" onClick={addLine} className="text-sm text-[#1F6F5C]">
                + Add line
              </button>
            )}
          </div>
          {errors.lines && <p className="text-sm text-red-600 mb-2">{errors.lines}</p>}
          <div className="space-y-3">
            {lines.map((line, i) => (
              <div key={i} className="border border-gray-200 rounded-lg p-4 bg-gray-50/50 space-y-3">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <FormField label="Item">
                    <select
                      value={line.item_id}
                      onChange={(e) => updateLine(i, { item_id: e.target.value })}
                      disabled={!canEdit}
                      className="w-full px-2 py-1 border rounded text-sm min-h-[44px]"
                    >
                      <option value="">Select item</option>
                      {items?.map((it) => (
                        <option key={it.id} value={it.id}>
                          {it.name} ({it.uom?.code})
                        </option>
                      ))}
                    </select>
                  </FormField>
                  <FormField label="Qty delta">
                    <input
                      type="number"
                      step="any"
                      value={line.qty_delta}
                      onChange={(e) => updateLine(i, { qty_delta: e.target.value })}
                      disabled={!canEdit}
                      placeholder="-2 or 3"
                      className="w-full px-2 py-1 border rounded text-sm min-h-[44px]"
                    />
                  </FormField>
                </div>
                {canEdit && (
                  <div className="flex justify-end">
                    <button type="button" onClick={() => removeLine(i)} className="text-red-600 text-sm">
                      Remove
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
        {canEdit && (
          <div className="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-4 border-t">
            <button type="button" onClick={() => navigate('/app/inventory/adjustments')} className="px-4 py-2 border rounded min-h-[44px]">
              Cancel
            </button>
            <button
              onClick={handleSubmit}
              disabled={!canSaveDraft || createM.isPending}
              title={!canSaveDraft ? 'Complete the checklist above before saving.' : undefined}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50 min-h-[44px]"
            >
              {createM.isPending ? 'Saving…' : 'Save draft'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
