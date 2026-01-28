import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useIssue,
  useUpdateIssue,
  usePostIssue,
  useReverseIssue,
  useInventoryStores,
  useInventoryItems,
  useStockOnHand,
} from '../../hooks/useInventory';
import { useQuery } from '@tanstack/react-query';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useProjectRule } from '../../hooks/useProjectRules';
import { useParties } from '../../hooks/useParties';
import { shareRulesApi } from '../../api/shareRules';
import { useMachinesQuery } from '../../hooks/useMachinery';
import { useModules } from '../../contexts/ModulesContext';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';
import type { UpdateInvIssuePayload } from '../../types';

type Line = { item_id: string; qty: string };

export default function InvIssueDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: issue, isLoading } = useIssue(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/inventory/issues';
  const updateM = useUpdateIssue();
  const postM = usePostIssue();
  const reverseM = useReverseIssue();
  const { data: cropCycles } = useCropCycles();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { data: stock } = useStockOnHand(issue?.store_id ? { store_id: issue.store_id } : undefined);
  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  const [doc_no, setDocNo] = useState('');
  const [store_id, setStoreId] = useState('');
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [activity_id, setActivityId] = useState('');
  const [machine_id, setMachineId] = useState('');
  const [doc_date, setDocDate] = useState('');
  const [lines, setLines] = useState<Line[]>([]);
  const [allocation_mode, setAllocationMode] = useState<'SHARED' | 'HARI_ONLY' | 'FARMER_ONLY' | ''>('');
  const [hari_id, setHariId] = useState('');
  /** __project__ = use project rule (grey out %), __manual__ = use percentages below, or share rule uuid */
  const [splitSource, setSplitSource] = useState<'__project__' | '__manual__' | string>('__manual__');
  const [landlord_share_pct, setLandlordSharePct] = useState('');
  const [hari_share_pct, setHariSharePct] = useState('');

  const { data: projectsForCrop } = useProjects(crop_cycle_id || issue?.crop_cycle_id);
  const { data: projectRule } = useProjectRule(project_id || '');
  const { data: parties } = useParties();
  const { data: shareRules } = useQuery({
    queryKey: ['shareRules', crop_cycle_id || issue?.crop_cycle_id],
    queryFn: () => shareRulesApi.list({ crop_cycle_id: (crop_cycle_id || issue?.crop_cycle_id) || undefined, is_active: true }),
    enabled: !!(crop_cycle_id || issue?.crop_cycle_id) && allocation_mode === 'SHARED',
  });
  const { isModuleEnabled } = useModules();
  const machineryEnabled = isModuleEnabled('machinery');
  const { data: machines } = useMachinesQuery(undefined);
  const hariParties = parties?.filter((p) => p.party_types?.includes('HARI')) || [];

  useEffect(() => {
    if (issue) {
      setDocNo(issue.doc_no);
      setStoreId(issue.store_id);
      setCropCycleId(issue.crop_cycle_id);
      setProjectId(issue.project_id);
      setActivityId(issue.activity_id || '');
      setMachineId(issue.machine_id || '');
      setDocDate(issue.doc_date);
      setLines((issue.lines || []).map((l) => ({ item_id: l.item_id, qty: String(l.qty) })));
      setAllocationMode((issue.allocation_mode as 'SHARED' | 'HARI_ONLY' | 'FARMER_ONLY') || '');
      setHariId(issue.hari_id || '');
      if (issue.sharing_rule_id) {
        setSplitSource(issue.sharing_rule_id);
        setLandlordSharePct('');
        setHariSharePct('');
      } else {
        const lp = issue.landlord_share_pct != null ? String(issue.landlord_share_pct) : '';
        const hp = issue.hari_share_pct != null ? String(issue.hari_share_pct) : '';
        setLandlordSharePct(lp);
        setHariSharePct(hp);
        setSplitSource(lp && hp ? '__manual__' : '__project__');
      }
      if (!showPostModal && !showReverseModal) setPostingDate(new Date().toISOString().split('T')[0]);
    }
  }, [issue, showPostModal, showReverseModal]);

  // Sync display % from project rule when "Use project values"
  useEffect(() => {
    if (allocation_mode === 'SHARED' && splitSource === '__project__' && projectRule) {
      setLandlordSharePct(String(projectRule.profit_split_landlord_pct ?? ''));
      setHariSharePct(String(projectRule.profit_split_hari_pct ?? ''));
    }
  }, [allocation_mode, splitSource, projectRule]);

  // Fall back to "Use percentages below" when project has no rules but we're on "Use project values"
  useEffect(() => {
    if (allocation_mode === 'SHARED' && splitSource === '__project__' && !projectRule) {
      setSplitSource('__manual__');
    }
  }, [allocation_mode, splitSource, projectRule]);

  const isDraft = issue?.status === 'DRAFT';
  const isPosted = issue?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const getAvail = (itemId: string) => {
    const r = stock?.find((s) => s.item_id === itemId);
    return r ? String(r.qty_on_hand) : '—';
  };

  const allocationValid = (): boolean => {
    if (!allocation_mode) return false;
    if (allocation_mode === 'HARI_ONLY') return !!hari_id;
    if (allocation_mode === 'SHARED') {
      if (splitSource !== '__project__' && splitSource !== '__manual__') return true;
      if (splitSource === '__project__') return !!projectRule && projectRule.profit_split_landlord_pct != null && projectRule.profit_split_hari_pct != null;
      const lp = parseFloat(landlord_share_pct);
      const hp = parseFloat(hari_share_pct);
      return !isNaN(lp) && !isNaN(hp) && Math.abs(lp + hp - 100) < 0.01;
    }
    return true;
  };

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
    const validLines = lines
      .filter((l) => l.item_id && parseFloat(l.qty) > 0)
      .map((l) => ({ item_id: l.item_id, qty: parseFloat(l.qty) }));
    if (validLines.length === 0) return;
    const payload: UpdateInvIssuePayload = {
      doc_no,
      store_id,
      crop_cycle_id,
      project_id,
      activity_id: activity_id || undefined,
      machine_id: machine_id || undefined,
      doc_date,
      lines: validLines,
      allocation_mode: allocation_mode || undefined,
      ...(allocation_mode === 'HARI_ONLY' && hari_id ? { hari_id } : {}),
      ...(allocation_mode === 'SHARED' && splitSource !== '__project__' && splitSource !== '__manual__' ? { sharing_rule_id: splitSource } : {}),
      ...(allocation_mode === 'SHARED' && (splitSource === '__project__' || splitSource === '__manual__') && landlord_share_pct && hari_share_pct
        ? { landlord_share_pct: parseFloat(landlord_share_pct), hari_share_pct: parseFloat(hari_share_pct) }
        : {}),
    };
    await updateM.mutateAsync({ id, payload });
  };

  const handlePost = async () => {
    if (!id) return;
    await postM.mutateAsync({ id, payload: { posting_date: postingDate, idempotency_key: idempotencyKey } });
    setShowPostModal(false);
  };

  const handleReverse = async () => {
    if (!id || !reverseReason.trim()) return;
    await reverseM.mutateAsync({ id, payload: { posting_date: postingDate, reason: reverseReason } });
    setShowReverseModal(false);
    setReverseReason('');
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!issue) return <div>Issue not found.</div>;

  return (
    <div>
      <PageHeader
        title={`Issue ${issue.doc_no}`}
        backTo={backTo}
        breadcrumbs={[
          { label: 'Inventory', to: '/app/inventory' },
          { label: 'Issues', to: '/app/inventory/issues' },
          { label: issue.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{issue.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">Store</dt><dd>{issue.store?.name || issue.store_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Crop Cycle</dt><dd>{issue.crop_cycle?.name || issue.crop_cycle_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Project</dt><dd>{issue.project?.name || issue.project_id}</dd></div>
          {issue.machine && (
            <div><dt className="text-sm text-gray-500">Machine</dt><dd>{issue.machine.code} - {issue.machine.name}</dd></div>
          )}
          <div><dt className="text-sm text-gray-500">Doc Date</dt><dd>{formatDate(issue.doc_date)}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><span className={`px-2 py-1 rounded text-xs ${
              issue.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
              issue.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
            }`}>{issue.status}</span></dd>
          </div>
          {issue.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Posting Group</dt>
              <dd><Link to={`/app/posting-groups/${issue.posting_group_id}`} className="text-[#1F6F5C]">{issue.posting_group_id}</Link></dd>
            </div>
          )}
          {issue.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{formatDate(issue.posting_date)}</dd></div>}
          {issue.allocation_mode && (
            <div><dt className="text-sm text-gray-500">Cost Ownership</dt><dd className="font-medium">{issue.allocation_mode}</dd></div>
          )}
          {issue.allocation_mode === 'HARI_ONLY' && issue.hari && (
            <div><dt className="text-sm text-gray-500">Hari</dt><dd>{issue.hari.name}</dd></div>
          )}
          {issue.allocation_mode === 'SHARED' && issue.sharing_rule && (
            <div><dt className="text-sm text-gray-500">Sharing Rule</dt><dd>{issue.sharing_rule.name}</dd></div>
          )}
          {issue.allocation_mode === 'SHARED' && issue.landlord_share_pct && issue.hari_share_pct && (
            <>
              <div><dt className="text-sm text-gray-500">Landlord Share</dt><dd>{issue.landlord_share_pct}%</dd></div>
              <div><dt className="text-sm text-gray-500">Hari Share</dt><dd>{issue.hari_share_pct}%</dd></div>
            </>
          )}
        </dl>
      </div>

      {isDraft && canEdit ? (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-4">Edit (DRAFT)</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Doc No"><input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Doc Date"><input type="date" value={doc_date} onChange={(e) => setDocDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Store">
              <select value={store_id} onChange={(e) => setStoreId(e.target.value)} className="w-full px-3 py-2 border rounded">
                {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </FormField>
            <FormField label="Crop Cycle">
              <select value={crop_cycle_id} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} className="w-full px-3 py-2 border rounded">
                {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </FormField>
            <FormField label="Project">
              <select value={project_id} onChange={(e) => setProjectId(e.target.value)} className="w-full px-3 py-2 border rounded">
                <option value="">Select</option>
                {(projectsForCrop || [])?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </FormField>
            <FormField label="Activity"><input value={activity_id} onChange={(e) => setActivityId(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            {machineryEnabled && (
              <FormField label="Machine (optional)">
                <select
                  value={machine_id}
                  onChange={(e) => setMachineId(e.target.value)}
                  className="w-full px-3 py-2 border rounded"
                >
                  <option value="">Select machine (optional)</option>
                  {machines?.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.code} - {m.name}
                    </option>
                  ))}
                </select>
              </FormField>
            )}
          </div>
          <div className="border-t pt-4 mb-4">
            <h4 className="font-medium mb-2">Cost Ownership (required to post)</h4>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <FormField label="Cost Ownership">
                <select
                  value={allocation_mode}
                  onChange={(e) => {
                    const v = e.target.value as 'SHARED' | 'HARI_ONLY' | 'FARMER_ONLY' | '';
                    setAllocationMode(v);
                    if (v !== 'HARI_ONLY') setHariId('');
                    if (v !== 'SHARED') {
                      setSplitSource('__manual__');
                      setLandlordSharePct('');
                      setHariSharePct('');
                    }
                  }}
                  className="w-full px-3 py-2 border rounded"
                >
                  <option value="">Select</option>
                  <option value="SHARED">Shared</option>
                  <option value="HARI_ONLY">Hari Only</option>
                  <option value="FARMER_ONLY">Landlord Only</option>
                </select>
              </FormField>
              {allocation_mode === 'HARI_ONLY' && (
                <FormField label="Hari">
                  <select
                    value={hari_id}
                    onChange={(e) => setHariId(e.target.value)}
                    className="w-full px-3 py-2 border rounded"
                  >
                    <option value="">Select Hari</option>
                    {hariParties.map((p) => (
                      <option key={p.id} value={p.id}>{p.name}</option>
                    ))}
                  </select>
                </FormField>
              )}
              {allocation_mode === 'SHARED' && (
                <>
                  <FormField label="Split source">
                    <select
                      value={splitSource}
                      onChange={(e) => {
                        const v = e.target.value;
                        setSplitSource(v);
                        if (v === '__manual__') {
                          if (!landlord_share_pct && !hari_share_pct && projectRule) {
                            setLandlordSharePct(String(projectRule.profit_split_landlord_pct ?? ''));
                            setHariSharePct(String(projectRule.profit_split_hari_pct ?? ''));
                          }
                        } else if (v !== '__project__') {
                          setLandlordSharePct('');
                          setHariSharePct('');
                        }
                      }}
                      className="w-full px-3 py-2 border rounded"
                    >
                      <option value="__project__" disabled={!projectRule}>
                        {projectRule ? 'Use project values' : 'Use project values (set project rules first)'}
                      </option>
                      <option value="__manual__">Use percentages below</option>
                      {shareRules?.map((r) => (
                        <option key={r.id} value={r.id}>{r.name}</option>
                      ))}
                    </select>
                  </FormField>
                  {splitSource === '__project__' && (
                    <>
                      <FormField label="Landlord Share %">
                        <input
                          type="text"
                          readOnly
                          value={projectRule ? (projectRule.profit_split_landlord_pct ?? '—') : '—'}
                          className="w-full px-3 py-2 border rounded bg-gray-100 text-gray-600 cursor-not-allowed"
                        />
                      </FormField>
                      <FormField label="Hari Share %">
                        <input
                          type="text"
                          readOnly
                          value={projectRule ? (projectRule.profit_split_hari_pct ?? '—') : '—'}
                          className="w-full px-3 py-2 border rounded bg-gray-100 text-gray-600 cursor-not-allowed"
                        />
                      </FormField>
                    </>
                  )}
                  {splitSource === '__manual__' && (
                    <>
                      <FormField label="Landlord Share %">
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          max="100"
                          value={landlord_share_pct}
                          onChange={(e) => setLandlordSharePct(e.target.value)}
                          className="w-full px-3 py-2 border rounded"
                          placeholder="50"
                        />
                      </FormField>
                      <FormField label="Hari Share %">
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          max="100"
                          value={hari_share_pct}
                          onChange={(e) => setHariSharePct(e.target.value)}
                          className="w-full px-3 py-2 border rounded"
                          placeholder="50"
                        />
                      </FormField>
                    </>
                  )}
                </>
              )}
            </div>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2"><h4 className="font-medium">Lines</h4><button type="button" onClick={addLine} className="text-sm text-[#1F6F5C]">+ Add</button></div>
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]">
                <tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Available</th><th className="w-10" /></tr>
              </thead>
              <tbody>
                {lines.map((line, i) => (
                  <tr key={i}>
                    <td className="px-3 py-2">
                      <select value={line.item_id} onChange={(e) => updateLine(i, { item_id: e.target.value })} className="w-full px-2 py-1 border rounded text-sm">
                        <option value="">Select</option>
                        {items?.map((it) => <option key={it.id} value={it.id}>{it.name}</option>)}
                      </select>
                    </td>
                    <td className="px-3 py-2"><input type="number" step="any" min="0" value={line.qty} onChange={(e) => updateLine(i, { qty: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" /></td>
                    <td className="px-3 py-2 text-sm">{getAvail(line.item_id)}</td>
                    <td><button type="button" onClick={() => removeLine(i)} className="text-red-600 text-sm">Del</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {canPost && !allocationValid() && (
            <p className="text-amber-700 text-sm mb-2">Set cost ownership above and save before posting.</p>
          )}
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={updateM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded">Save</button>
            {canPost && (
              <button
                onClick={() => setShowPostModal(true)}
                disabled={!allocationValid()}
                className="px-4 py-2 bg-green-600 text-white rounded disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Post
              </button>
            )}
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-2">Lines</h3>
          <table className="min-w-full border">
            <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Unit cost</th><th className="px-3 py-2 text-left text-xs text-gray-500">Total</th></tr></thead>
            <tbody>
              {(issue.lines || []).map((l) => (
                <tr key={l.id}>
                  <td className="px-3 py-2">{l.item?.name}</td>
                  <td>{l.qty}</td>
                  <td>{l.unit_cost_snapshot != null ? <span className="tabular-nums">{formatMoney(l.unit_cost_snapshot)}</span> : '—'}</td>
                  <td>{l.line_total != null ? <span className="tabular-nums">{formatMoney(l.line_total)}</span> : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {issue.lines && issue.lines.some((l) => l.line_total) && (
            <p className="mt-2 font-medium">Total: <span className="tabular-nums">{formatMoney((issue.lines || []).reduce((a, l) => a + parseFloat(String(l.line_total || 0)), 0))}</span></p>
          )}
        </div>
      )}

      {isPosted && issue.posting_group?.allocation_rows && issue.posting_group.allocation_rows.length > 0 && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-4">Cost Allocations</h3>
          <table className="min-w-full border">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Party</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Type</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Amount</th>
              </tr>
            </thead>
            <tbody>
              {issue.posting_group.allocation_rows.map((row) => (
                <tr key={row.id}>
                  <td className="px-3 py-2">{row.party?.name || row.party_id}</td>
                  <td className="px-3 py-2">{row.allocation_type}</td>
                  <td className="px-3 py-2 text-right">
                    <span className="tabular-nums">{formatMoney(row.amount)}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {isPosted && canPost && (
        <div className="mb-6">
          <button onClick={() => setShowReverseModal(true)} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
        </div>
      )}

      <Modal isOpen={showPostModal} onClose={() => setShowPostModal(false)} title="Post Issue">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Idempotency Key"><input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" /></FormField>
          
          {issue.allocation_mode && issue.lines && (
            <div className="border-t pt-4">
              <h4 className="font-medium mb-2">Allocation Preview</h4>
              {(() => {
                const totalValue = (issue.lines || []).reduce((sum, l) => sum + parseFloat(String(l.line_total || 0)), 0);
                if (issue.allocation_mode === 'HARI_ONLY') {
                  return (
                    <div className="text-sm space-y-1">
                      <div>Total Issue Value: <span className="font-medium">{formatMoney(totalValue)}</span></div>
                      <div>Hari Share: <span className="font-medium">{formatMoney(totalValue)}</span> (100%)</div>
                    </div>
                  );
                } else if (issue.allocation_mode === 'FARMER_ONLY') {
                  return (
                    <div className="text-sm space-y-1">
                      <div>Total Issue Value: <span className="font-medium">{formatMoney(totalValue)}</span></div>
                      <div>Landlord Share: <span className="font-medium">{formatMoney(totalValue)}</span> (100%)</div>
                    </div>
                  );
                } else if (issue.allocation_mode === 'SHARED') {
                  const landlordPct = issue.landlord_share_pct ? parseFloat(issue.landlord_share_pct) : 50;
                  const hariPct = issue.hari_share_pct ? parseFloat(issue.hari_share_pct) : 50;
                  const landlordShare = totalValue * (landlordPct / 100);
                  const hariShare = totalValue * (hariPct / 100);
                  return (
                    <div className="text-sm space-y-1">
                      <div>Total Issue Value: <span className="font-medium">{formatMoney(totalValue)}</span></div>
                      <div>Landlord Share: <span className="font-medium">{formatMoney(landlordShare)}</span> ({landlordPct}%)</div>
                      <div>Hari Share: <span className="font-medium">{formatMoney(hariShare)}</span> ({hariPct}%)</div>
                    </div>
                  );
                }
                return null;
              })()}
            </div>
          )}
          
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowPostModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handlePost} disabled={postM.isPending} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>
          </div>
        </div>
      </Modal>

      <Modal isOpen={showReverseModal} onClose={() => setShowReverseModal(false)} title="Reverse Issue">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Reason" required><textarea value={reverseReason} onChange={(e) => setReverseReason(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowReverseModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleReverse} disabled={!reverseReason.trim() || reverseM.isPending} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
