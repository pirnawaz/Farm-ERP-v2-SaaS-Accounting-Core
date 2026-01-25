import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateIssue } from '../../hooks/useInventory';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import type { CreateInvIssuePayload } from '../../types';

type Line = { item_id: string; qty: string };

export default function InvIssueFormPage() {
  const navigate = useNavigate();
  const createM = useCreateIssue();
  const { data: cropCycles } = useCropCycles();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();

  const [doc_no, setDocNo] = useState('');
  const [store_id, setStoreId] = useState('');
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [activity_id, setActivityId] = useState('');
  const [doc_date, setDocDate] = useState(new Date().toISOString().split('T')[0]);
  const [lines, setLines] = useState<Line[]>([{ item_id: '', qty: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { data: projectsForCrop } = useProjects(crop_cycle_id || undefined);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!doc_no.trim()) e.doc_no = 'Doc number is required';
    if (!store_id) e.store_id = 'Store is required';
    if (!crop_cycle_id) e.crop_cycle_id = 'Crop cycle is required';
    if (!project_id) e.project_id = 'Project is required';
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
    const payload: CreateInvIssuePayload = {
      doc_no: doc_no.trim(),
      store_id,
      crop_cycle_id,
      project_id,
      activity_id: activity_id || undefined,
      doc_date,
      lines: validLines,
    };
    const issue = await createM.mutateAsync(payload);
    navigate(`/app/inventory/issues/${issue.id}`);
  };

  return (
    <div>
      <PageHeader
        title="New Issue"
        backTo="/app/inventory/issues"
        breadcrumbs={[
          { label: 'Inventory', to: '/app/inventory' },
          { label: 'Issues', to: '/app/inventory/issues' },
          { label: 'New Issue' },
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
          <FormField label="Store" required error={errors.store_id}>
            <select value={store_id} onChange={(e) => setStoreId(e.target.value)} disabled={!canEdit} className="w-full px-3 py-2 border rounded">
              <option value="">Select store</option>
              {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </FormField>
          <FormField label="Crop Cycle" required error={errors.crop_cycle_id}>
            <select value={crop_cycle_id} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} disabled={!canEdit} className="w-full px-3 py-2 border rounded">
              <option value="">Select crop cycle</option>
              {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FormField>
          <FormField label="Project" required error={errors.project_id}>
            <select value={project_id} onChange={(e) => setProjectId(e.target.value)} disabled={!canEdit || !crop_cycle_id} className="w-full px-3 py-2 border rounded">
              <option value="">Select project</option>
              {(projectsForCrop || [])?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </FormField>
          <FormField label="Activity (optional)">
            <input value={activity_id} onChange={(e) => setActivityId(e.target.value)} disabled={!canEdit} className="w-full px-3 py-2 border rounded" placeholder="UUID or leave blank" />
          </FormField>
        </div>

        <div>
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium">Lines</h3>
            {canEdit && <button type="button" onClick={addLine} className="text-sm text-blue-600">+ Add line</button>}
          </div>
          {errors.lines && <p className="text-sm text-red-600 mb-2">{errors.lines}</p>}
          <div className="overflow-x-auto">
            <table className="min-w-full border">
              <thead className="bg-gray-50">
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
                        min="0"
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
            <button type="button" onClick={() => navigate('/app/inventory/issues')} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleSubmit} disabled={createM.isPending} className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">
              {createM.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
