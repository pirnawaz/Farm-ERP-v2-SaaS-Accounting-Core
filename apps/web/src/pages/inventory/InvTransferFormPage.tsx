import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateTransfer, useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import type { CreateInvTransferPayload } from '../../types';

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

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!doc_no.trim()) e.doc_no = 'Doc number is required';
    if (!from_store_id) e.from_store_id = 'From store is required';
    if (!to_store_id) e.to_store_id = 'To store is required';
    if (from_store_id && to_store_id && from_store_id === to_store_id) {
      e.to_store_id = 'From and To store must be different';
    }
    if (!doc_date) e.doc_date = 'Doc date is required';
    const validLines = lines.filter((l) => l.item_id && parseFloat(l.qty) > 0);
    if (validLines.length === 0) e.lines = 'At least one line with item and qty > 0 is required';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async () => {
    if (!validate() || !canEdit) return;
    const validLines = lines
      .filter((l) => l.item_id && parseFloat(l.qty) > 0)
      .map((l) => ({ item_id: l.item_id, qty: parseFloat(l.qty) }));
    const payload: CreateInvTransferPayload = {
      doc_no: doc_no.trim(),
      from_store_id,
      to_store_id,
      doc_date,
      lines: validLines,
    };
    const transfer = await createM.mutateAsync(payload);
    navigate(`/app/inventory/transfers/${transfer.id}`);
  };

  return (
    <div>
      <PageHeader
        title="New Transfer"
        backTo="/app/inventory/transfers"
        breadcrumbs={[
          { label: 'Inventory', to: '/app/inventory' },
          { label: 'Transfers', to: '/app/inventory/transfers' },
          { label: 'New Transfer' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Doc No" required error={errors.doc_no}>
            <input value={doc_no} onChange={(e) => setDocNo(e.target.value)} disabled={!canEdit} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Doc Date" required error={errors.doc_date}>
            <input type="date" value={doc_date} onChange={(e) => setDocDate(e.target.value)} disabled={!canEdit} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="From Store" required error={errors.from_store_id}>
            <select value={from_store_id} onChange={(e) => setFromStoreId(e.target.value)} disabled={!canEdit} className="w-full px-3 py-2 border rounded">
              <option value="">Select store</option>
              {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </FormField>
          <FormField label="To Store" required error={errors.to_store_id}>
            <select value={to_store_id} onChange={(e) => setToStoreId(e.target.value)} disabled={!canEdit} className="w-full px-3 py-2 border rounded">
              <option value="">Select store</option>
              {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </FormField>
        </div>

        <div>
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium">Lines</h3>
            {canEdit && <button type="button" onClick={addLine} className="text-sm text-[#1F6F5C]">+ Add line</button>}
          </div>
          {errors.lines && <p className="text-sm text-red-600 mb-2">{errors.lines}</p>}
          <div className="overflow-x-auto">
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Item</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Qty</th>
                  {canEdit && <th className="px-3 py-2 w-10" />}
                </tr>
              </thead>
              <tbody>
                {lines.map((line, i) => (
                  <tr key={i}>
                    <td className="px-3 py-2">
                      <select
                        value={line.item_id}
                        onChange={(e) => updateLine(i, { item_id: e.target.value })}
                        disabled={!canEdit}
                        className="w-full px-2 py-1 border rounded text-sm"
                      >
                        <option value="">Select item</option>
                        {items?.map((it) => <option key={it.id} value={it.id}>{it.name} ({it.uom?.code})</option>)}
                      </select>
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="number"
                        step="any"
                        min="0.000001"
                        value={line.qty}
                        onChange={(e) => updateLine(i, { qty: e.target.value })}
                        disabled={!canEdit}
                        className="w-24 px-2 py-1 border rounded text-sm"
                      />
                    </td>
                    {canEdit && (
                      <td className="px-3 py-2">
                        <button type="button" onClick={() => removeLine(i)} className="text-red-600 text-sm">Remove</button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {canEdit && (
          <div className="flex justify-end gap-2 pt-4">
            <button type="button" onClick={() => navigate('/app/inventory/transfers')} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleSubmit} disabled={createM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50">
              {createM.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
