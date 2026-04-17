import { useState, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateTransfer, useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { term } from '../../config/terminology';
import type { CreateInvTransferPayload } from '../../types';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';
import { getStored, setStored, formStorageKeys } from '../../utils/formDefaults';
import { useBlockUnsavedNavigation } from '../../hooks/useBlockUnsavedNavigation';

type Line = { item_id: string; qty: string };

export default function InvTransferFormPage() {
  const navigate = useNavigate();
  const createM = useCreateTransfer();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();

  const [doc_no, setDocNo] = useState('');
  const [from_store_id, setFromStoreId] = useState('');
  const [to_store_id, setToStoreId] = useState('');
  const [doc_date, setDocDate] = useState(new Date().toISOString().split('T')[0]);
  const [lines, setLines] = useState<Line[]>([{ item_id: '', qty: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [touched, setTouched] = useState(false);
  const markTouched = () => setTouched(true);

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  useEffect(() => {
    if (!stores?.length) return;
    if (stores.length === 1) {
      if (!from_store_id) setFromStoreId(stores[0].id);
      return;
    }
    const lastFrom = getStored<string>(formStorageKeys.last_transfer_from_store_id);
    const lastTo = getStored<string>(formStorageKeys.last_transfer_to_store_id);
    if (lastFrom && stores.some((s) => s.id === lastFrom) && !from_store_id) setFromStoreId(lastFrom);
    if (lastTo && stores.some((s) => s.id === lastTo) && !to_store_id) setToStoreId(lastTo);
  }, [stores, from_store_id, to_store_id]);

  useEffect(() => {
    if (from_store_id) setStored(formStorageKeys.last_transfer_from_store_id, from_store_id);
  }, [from_store_id]);
  useEffect(() => {
    if (to_store_id) setStored(formStorageKeys.last_transfer_to_store_id, to_store_id);
  }, [to_store_id]);

  useBlockUnsavedNavigation(touched && canEdit);

  const addLine = () => {
    markTouched();
    setLines((l) => [...l, { item_id: '', qty: '' }]);
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
    () => lines.filter((l) => l.item_id && parseFloat(l.qty) > 0),
    [lines]
  );

  const storesDistinct =
    Boolean(from_store_id && to_store_id) && from_store_id !== to_store_id;

  const canSaveDraft =
    Boolean(from_store_id && to_store_id && storesDistinct && doc_date && validLines.length > 0);

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!from_store_id) e.from_store_id = 'From store is required';
    if (!to_store_id) e.to_store_id = 'To store is required';
    if (from_store_id && to_store_id && from_store_id === to_store_id) {
      e.to_store_id = 'From and To store must be different';
    }
    if (!doc_date) e.doc_date = 'Doc date is required';
    if (validLines.length === 0) e.lines = 'At least one line with item and qty > 0 is required';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const readinessItems = useMemo(
    () => [
      { ok: Boolean(from_store_id), label: 'From store selected' },
      { ok: Boolean(to_store_id), label: 'To store selected' },
      { ok: storesDistinct, label: 'From and to are different stores' },
      { ok: Boolean(doc_date), label: 'Document date set' },
      { ok: validLines.length > 0, label: 'At least one line with item and quantity > 0' },
    ],
    [from_store_id, to_store_id, storesDistinct, doc_date, validLines.length]
  );

  const handleSubmit = async () => {
    if (!canSaveDraft || !validate() || !canEdit) return;
    const payloadLines = validLines.map((l) => ({ item_id: l.item_id, qty: parseFloat(l.qty) }));
    const payload: CreateInvTransferPayload = {
      ...(doc_no.trim() && { doc_no: doc_no.trim() }),
      from_store_id,
      to_store_id,
      doc_date,
      lines: payloadLines,
    };
    const transfer = await createM.mutateAsync(payload);
    navigate(`/app/inventory/transfers/${transfer.id}`);
  };

  return (
    <div className="space-y-6 pb-8">
      <PageHeader
        title="New stock transfer"
        description="Move quantity from one store to another within your operation."
        helper="Not a goods receipt or a stock issue—only reallocates stock between locations. Saving creates a draft until you record to accounts on the next screen."
        backTo="/app/inventory/transfers"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Inventory Overview', to: '/app/inventory' },
          { label: term('transfer'), to: '/app/inventory/transfers' },
          { label: 'New stock transfer' },
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
          <FormField label="From Store" required error={errors.from_store_id}>
            <select
              value={from_store_id}
              onChange={(e) => {
                markTouched();
                setFromStoreId(e.target.value);
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
          <FormField label="To Store" required error={errors.to_store_id}>
            <select
              value={to_store_id}
              onChange={(e) => {
                markTouched();
                setToStoreId(e.target.value);
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
        </div>

        <div className="space-y-3">
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium">Lines</h3>
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
                  <FormField label="Qty">
                    <input
                      type="number"
                      step="any"
                      min="0.000001"
                      value={line.qty}
                      onChange={(e) => updateLine(i, { qty: e.target.value })}
                      disabled={!canEdit}
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
            <button type="button" onClick={() => navigate('/app/inventory/transfers')} className="px-4 py-2 border rounded min-h-[44px]">
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
