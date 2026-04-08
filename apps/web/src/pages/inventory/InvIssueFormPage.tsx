import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useCreateIssue } from '../../hooks/useInventory';
import { useActivities } from '../../hooks/useCropOps';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProductionUnits } from '../../hooks/useProductionUnits';
import { useProjects } from '../../hooks/useProjects';
import { useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { useMachinesQuery } from '../../hooks/useMachinery';
import { useModules } from '../../contexts/ModulesContext';
import { useOrchardLivestockAddonsEnabled } from '../../hooks/useModules';
import { useTenant } from '../../hooks/useTenant';
import { useFormAutosave } from '../../hooks/useFormAutosave';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { PageContainer } from '../../components/PageContainer';
import { FormCard } from '../../components/FormLayout';
import { useRole } from '../../hooks/useRole';
import { useParties } from '../../hooks/useParties';
import { useProjectRule } from '../../hooks/useProjectRules';
import { shareRulesApi } from '../../api/shareRules';
import { generateDocNo, getActiveCropCycleId, getStored, setStored, formStorageKeys, getLastSubmit, setLastSubmit } from '../../utils/formDefaults';
import { term } from '../../config/terminology';
import type { CreateInvIssuePayload } from '../../types';

type Line = { item_id: string; qty: string };

type InvIssueSnapshot = {
  doc_no: string;
  store_id: string;
  crop_cycle_id: string;
  project_id: string;
  production_unit_id: string;
  activity_id: string;
  machine_id: string;
  doc_date: string;
  lines: Line[];
  allocation_mode: 'SHARED' | 'HARI_ONLY' | 'FARMER_ONLY';
  hari_id: string;
  splitSource: string;
  landlord_share_pct: string;
  hari_share_pct: string;
};

export default function InvIssueFormPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { tenantId } = useTenant();
  const createM = useCreateIssue();
  const { data: cropCycles } = useCropCycles();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();

  const [doc_no, setDocNo] = useState('');
  const [store_id, setStoreId] = useState('');
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [production_unit_id, setProductionUnitId] = useState('');
  const [activity_id, setActivityId] = useState('');
  const [machine_id, setMachineId] = useState('');
  const [doc_date, setDocDate] = useState(new Date().toISOString().split('T')[0]);
  const [lines, setLines] = useState<Line[]>([{ item_id: '', qty: '' }]);
  const [allocation_mode, setAllocationMode] = useState<'SHARED' | 'HARI_ONLY' | 'FARMER_ONLY'>('SHARED');
  const [hari_id, setHariId] = useState('');
  /** __project__ = use project rule (grey out %), __manual__ = use percentages below, or share rule uuid */
  const [splitSource, setSplitSource] = useState<'__project__' | '__manual__' | string>('__manual__');
  const [landlord_share_pct, setLandlordSharePct] = useState('');
  const [hari_share_pct, setHariSharePct] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { data: productionUnits } = useProductionUnits();
  const { data: projectsForCrop } = useProjects(crop_cycle_id || undefined);
  const { data: activities } = useActivities(
    crop_cycle_id && project_id ? { crop_cycle_id, project_id } : undefined
  );
  const { data: projectRule } = useProjectRule(project_id || '');
  const { data: parties } = useParties();
  const { data: shareRules } = useQuery({
    queryKey: ['shareRules', crop_cycle_id],
    queryFn: () => shareRulesApi.list({ crop_cycle_id: crop_cycle_id || undefined, is_active: true }),
    enabled: !!crop_cycle_id && allocation_mode === 'SHARED',
  });
  const { isModuleEnabled } = useModules();
  const { hasOrchardLivestockModule } = useOrchardLivestockAddonsEnabled();
  const machineryEnabled = isModuleEnabled('machinery');
  const { data: machines } = useMachinesQuery(undefined);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  useEffect(() => {
    if (!hasOrchardLivestockModule) {
      setProductionUnitId('');
    }
  }, [hasOrchardLivestockModule]);

  // Get hari parties (parties with HARI type)
  const hariParties = parties?.filter((p) => p.party_types?.includes('HARI')) || [];
  
  // Get project's hari party if unique
  const selectedProject = projectsForCrop?.find((p) => p.id === project_id);
  const projectHariPartyId = selectedProject?.party_id;

  // Set default hari_id from project if unique and HARI_ONLY mode
  useEffect(() => {
    if (allocation_mode === 'HARI_ONLY' && projectHariPartyId && !hari_id) {
      setHariId(projectHariPartyId);
    }
  }, [allocation_mode, projectHariPartyId, hari_id]);

  // Sync display % from project rule when "Use project values"
  useEffect(() => {
    if (allocation_mode === 'SHARED' && splitSource === '__project__' && projectRule) {
      setLandlordSharePct(String(projectRule.profit_split_landlord_pct ?? ''));
      setHariSharePct(String(projectRule.profit_split_hari_pct ?? ''));
    }
  }, [allocation_mode, splitSource, projectRule]);

  // Default to "Use project values" when project has rules and we haven't chosen manual yet
  useEffect(() => {
    if (allocation_mode === 'SHARED' && projectRule && splitSource === '__manual__' && !landlord_share_pct && !hari_share_pct) {
      const lp = projectRule.profit_split_landlord_pct;
      const hp = projectRule.profit_split_hari_pct;
      if (lp != null && hp != null && !Number.isNaN(parseFloat(String(lp))) && !Number.isNaN(parseFloat(String(hp)))) {
        setSplitSource('__project__');
      }
    }
  }, [allocation_mode, projectRule, splitSource, landlord_share_pct, hari_share_pct]);

  // Fall back to "Use percentages below" when project has no rules but we're on "Use project values"
  useEffect(() => {
    if (allocation_mode === 'SHARED' && splitSource === '__project__' && !projectRule) {
      setSplitSource('__manual__');
    }
  }, [allocation_mode, splitSource, projectRule]);

  // Defaults: doc_no, active crop cycle, last store/project
  useEffect(() => {
    if (!doc_no) setDocNo(generateDocNo('ISS'));
  }, []);
  useEffect(() => {
    if (!cropCycles?.length) return;
    const activeId = getActiveCropCycleId(cropCycles);
    const storedId = getStored<string>(formStorageKeys.last_crop_cycle_id);
    const initial = storedId && cropCycles.some((c) => c.id === storedId) ? storedId : activeId;
    if (initial && !crop_cycle_id) setCropCycleId(initial);
  }, [cropCycles]);
  useEffect(() => {
    if (crop_cycle_id) setStored(formStorageKeys.last_crop_cycle_id, crop_cycle_id);
  }, [crop_cycle_id]);
  useEffect(() => {
    const stored = getStored<string>(formStorageKeys.last_store_id);
    if (stored && stores?.some((s) => s.id === stored) && !store_id) setStoreId(stored);
  }, [stores]);
  useEffect(() => {
    if (store_id) setStored(formStorageKeys.last_store_id, store_id);
  }, [store_id]);
  useEffect(() => {
    if (!projectsForCrop?.length) return;
    const stored = getStored<string>(formStorageKeys.last_project_id);
    if (stored && projectsForCrop.some((p) => p.id === stored) && !project_id) setProjectId(stored);
  }, [projectsForCrop]);
  useEffect(() => {
    if (project_id) setStored(formStorageKeys.last_project_id, project_id);
  }, [project_id]);
  useEffect(() => {
    if (!productionUnits?.length) return;
    if (!hasOrchardLivestockModule) return;
    const fromUrl = searchParams.get('production_unit_id');
    if (fromUrl && productionUnits.some((u) => u.id === fromUrl)) {
      setProductionUnitId(fromUrl);
      return;
    }
    const stored = getStored<string>(formStorageKeys.last_production_unit_id);
    if (stored && productionUnits.some((u) => u.id === stored) && !production_unit_id) setProductionUnitId(stored);
  }, [productionUnits, searchParams, hasOrchardLivestockModule]);
  useEffect(() => {
    if (!hasOrchardLivestockModule) return;
    if (production_unit_id) setStored(formStorageKeys.last_production_unit_id, production_unit_id);
  }, [production_unit_id, hasOrchardLivestockModule]);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const getSnapshot = useCallback(
    (): InvIssueSnapshot => ({
      doc_no,
      store_id,
      crop_cycle_id,
      project_id,
      production_unit_id,
      activity_id,
      machine_id,
      doc_date,
      lines: [...lines],
      allocation_mode,
      hari_id,
      splitSource,
      landlord_share_pct,
      hari_share_pct,
    }),
    [
      doc_no,
      store_id,
      crop_cycle_id,
      project_id,
      production_unit_id,
      activity_id,
      machine_id,
      doc_date,
      lines,
      allocation_mode,
      hari_id,
      splitSource,
      landlord_share_pct,
      hari_share_pct,
    ]
  );
  const applySnapshot = useCallback((data: InvIssueSnapshot) => {
    setDocNo(data.doc_no);
    setStoreId(data.store_id);
    setCropCycleId(data.crop_cycle_id);
    setProjectId(data.project_id);
    setProductionUnitId(data.production_unit_id);
    setActivityId(data.activity_id);
    setMachineId(data.machine_id);
    setDocDate(data.doc_date);
    setLines(data.lines.length ? data.lines : [{ item_id: '', qty: '' }]);
    setAllocationMode(data.allocation_mode);
    setHariId(data.hari_id);
    setSplitSource(data.splitSource);
    setLandlordSharePct(data.landlord_share_pct);
    setHariSharePct(data.hari_share_pct);
  }, []);

  const { hasDraft, restore, discard, clearDraft } = useFormAutosave<InvIssueSnapshot>({
    formId: 'inv-issue',
    tenantId: tenantId || '',
    context: crop_cycle_id ? { crop_cycle_id } : undefined,
    getSnapshot,
    applySnapshot,
    debounceMs: 4000,
    disabled: !tenantId,
  });

  const handleUseLast = () => {
    const last = getLastSubmit<CreateInvIssuePayload>(tenantId || '', 'inv-issue');
    if (!last) return;
    setStoreId(last.store_id || '');
    setCropCycleId(last.crop_cycle_id || '');
    setProjectId(last.project_id || '');
    setProductionUnitId(last.production_unit_id || '');
    setActivityId(last.activity_id || '');
    setMachineId(last.machine_id || '');
    setDocDate(last.doc_date || '');
    setLines(
      last.lines?.length
        ? last.lines.map((l) => ({ item_id: l.item_id, qty: typeof l.qty === 'number' ? String(l.qty) : l.qty }))
        : [{ item_id: '', qty: '' }]
    );
    if (last.allocation_mode) setAllocationMode(last.allocation_mode);
    if (last.hari_id) setHariId(last.hari_id);
    if (last.landlord_share_pct != null) setLandlordSharePct(String(last.landlord_share_pct));
    if (last.hari_share_pct != null) setHariSharePct(String(last.hari_share_pct));
  };
  const hasLast = !!tenantId && getLastSubmit(tenantId, 'inv-issue') != null;

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!store_id) e.store_id = 'Store is required';
    if (!crop_cycle_id) e.crop_cycle_id = 'Crop cycle is required';
    if (!project_id) e.project_id = 'Project is required';
    if (!doc_date) e.doc_date = 'Doc date is required';
    const validLines = lines.filter((l) => l.item_id && parseFloat(l.qty) > 0);
    if (validLines.length === 0) e.lines = 'At least one line with item and qty > 0 is required';
    
    // Validate allocation mode
    if (!allocation_mode) {
      e.allocation_mode = 'Cost ownership is required';
    } else if (allocation_mode === 'HARI_ONLY' && !hari_id) {
      e.hari_id = 'Hari is required for Hari Only allocation';
    } else if (allocation_mode === 'SHARED') {
      const useRule = splitSource !== '__project__' && splitSource !== '__manual__';
      const useProject = splitSource === '__project__';
      const useManual = splitSource === '__manual__';
      if (useRule) {
        // no extra validation
      } else if (useProject) {
        if (!projectRule) e.allocation_mode = 'Project rules required for "Use project values". Set rules for this project first.';
        else if (projectRule.profit_split_landlord_pct == null || projectRule.profit_split_hari_pct == null)
          e.allocation_mode = 'Project rule must define landlord and hari split %.';
      } else if (useManual) {
        if (!landlord_share_pct || !hari_share_pct) {
          e.allocation_mode = 'Both landlord and hari percentages are required';
        } else {
          const landlordPct = parseFloat(landlord_share_pct);
          const hariPct = parseFloat(hari_share_pct);
          if (isNaN(landlordPct) || isNaN(hariPct) || Math.abs(landlordPct + hariPct - 100) > 0.01) {
            e.landlord_share_pct = 'Landlord and Hari percentages must sum to 100';
          }
        }
      } else {
        e.allocation_mode = 'Choose "Use project values", "Use percentages below", or a sharing rule';
      }
    }
    
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async () => {
    if (!validate() || !canEdit) return;
    const validLines = lines
      .filter((l) => l.item_id && parseFloat(l.qty) > 0)
      .map((l) => ({ item_id: l.item_id, qty: parseFloat(l.qty) }));
    const finalDocNo = doc_no.trim() || generateDocNo('ISS');
    const payload: CreateInvIssuePayload = {
      ...(finalDocNo && { doc_no: finalDocNo }),
      store_id,
      crop_cycle_id,
      project_id,
      production_unit_id: production_unit_id || undefined,
      activity_id: activity_id || undefined,
      machine_id: machine_id || undefined,
      doc_date,
      lines: validLines,
      allocation_mode,
      ...(allocation_mode === 'HARI_ONLY' && hari_id ? { hari_id } : {}),
      ...(allocation_mode === 'SHARED' && splitSource !== '__project__' && splitSource !== '__manual__' ? { sharing_rule_id: splitSource } : {}),
      ...(allocation_mode === 'SHARED' && (splitSource === '__project__' || splitSource === '__manual__') && landlord_share_pct && hari_share_pct
        ? { landlord_share_pct: parseFloat(landlord_share_pct), hari_share_pct: parseFloat(hari_share_pct) }
        : {}),
    };
    const issue = await createM.mutateAsync(payload);
    setLastSubmit(tenantId || '', 'inv-issue', payload);
    clearDraft();
    navigate(`/app/inventory/issues/${issue.id}`);
  };

  return (
    <PageContainer width="form" className="space-y-6">
      <PageHeader
        title="Record Stock Used"
        backTo="/app/inventory/issues"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Inventory Overview', to: '/app/inventory' },
          { label: term('issue'), to: '/app/inventory/issues' },
          { label: 'Record Stock Used' },
        ]}
      />

      {hasDraft && (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex flex-wrap items-center gap-2">
          <span>Restore draft?</span>
          <button type="button" onClick={restore} className="font-medium text-[#1F6F5C] hover:underline">Restore</button>
          <span>|</span>
          <button type="button" onClick={discard} className="font-medium text-gray-600 hover:underline">Discard</button>
        </div>
      )}
      <FormCard>
        {hasLast && (
          <div className="flex justify-end">
            <button type="button" onClick={handleUseLast} className="text-sm font-medium text-[#1F6F5C] hover:underline">
              Use last values
            </button>
          </div>
        )}
        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Header</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Doc No">
            <input value={doc_no} onChange={(e) => setDocNo(e.target.value)} disabled={!canEdit} placeholder={generateDocNo('ISS')} className="w-full px-3 py-2.5 border border-gray-300 rounded-lg disabled:bg-gray-50" />
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
          {hasOrchardLivestockModule ? (
            <FormField label="Orchard / Livestock / Long-cycle unit (optional)">
              <select
                value={production_unit_id}
                onChange={(e) => setProductionUnitId(e.target.value)}
                disabled={!canEdit}
                className="w-full px-3 py-2 border rounded disabled:bg-gray-100"
              >
                <option value="">None</option>
                {(productionUnits ?? []).map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.name}
                    {u.type === 'SEASONAL' ? ' (legacy seasonal)' : ''}
                  </option>
                ))}
              </select>
              <p className="mt-1 text-xs text-gray-500">
                Optional tag for inputs used on orchards, livestock, or other long-cycle units. Leave blank for normal seasonal issues tied to
                the crop cycle only.
              </p>
            </FormField>
          ) : null}
          <FormField label={`${term('activities')} (optional)`}>
            <select
              value={activity_id}
              onChange={(e) => setActivityId(e.target.value)}
              disabled={!canEdit || !crop_cycle_id || !project_id}
              className="w-full px-3 py-2 border rounded disabled:bg-gray-100"
            >
              <option value="">None</option>
              {activities?.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.doc_no} {a.type?.name ? `– ${a.type.name}` : ''}
                </option>
              ))}
            </select>
          </FormField>
          {machineryEnabled && (
            <FormField label="Machine (optional)">
              <select
                value={machine_id}
                onChange={(e) => setMachineId(e.target.value)}
                disabled={!canEdit}
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
        </section>

        <div className="border-t pt-4">
          <h3 className="font-medium mb-4">Cost Ownership</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <FormField label="Cost Ownership" required error={errors.allocation_mode}>
              <select
                value={allocation_mode}
                onChange={(e) => {
                  setAllocationMode(e.target.value as 'SHARED' | 'HARI_ONLY' | 'FARMER_ONLY');
                if (e.target.value !== 'HARI_ONLY') setHariId('');
                if (e.target.value !== 'SHARED') {
                  setSplitSource('__manual__');
                  setLandlordSharePct('');
                  setHariSharePct('');
                }
                }}
                disabled={!canEdit}
                className="w-full px-3 py-2 border rounded"
              >
                <option value="SHARED">Shared</option>
                <option value="HARI_ONLY">Hari Only</option>
                <option value="FARMER_ONLY">Landlord Only</option>
              </select>
            </FormField>

            {allocation_mode === 'HARI_ONLY' && (
              <FormField label="Hari" required error={errors.hari_id}>
                <select
                  value={hari_id}
                  onChange={(e) => setHariId(e.target.value)}
                  disabled={!canEdit}
                  className="w-full px-3 py-2 border rounded"
                >
                  <option value="">Select Hari</option>
                  {hariParties.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
                </select>
              </FormField>
            )}

            {allocation_mode === 'SHARED' && (
              <>
                <FormField label="Split source" error={errors.allocation_mode}>
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
                    disabled={!canEdit}
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
                    <FormField label="Landlord Share %" error={errors.landlord_share_pct}>
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        value={landlord_share_pct}
                        onChange={(e) => setLandlordSharePct(e.target.value)}
                        disabled={!canEdit}
                        className="w-full px-3 py-2 border rounded"
                        placeholder="50"
                      />
                    </FormField>
                    <FormField label="Hari Share %" error={errors.hari_share_pct}>
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        value={hari_share_pct}
                        onChange={(e) => setHariSharePct(e.target.value)}
                        disabled={!canEdit}
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

        <section className="space-y-4">
          <div className="flex justify-between items-center">
            <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Lines</h2>
            {canEdit && <button type="button" onClick={addLine} className="text-sm font-medium text-[#1F6F5C] hover:underline">+ Add line</button>}
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
                      {items?.map((it) => <option key={it.id} value={it.id}>{it.name} ({it.uom?.code})</option>)}
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
                </div>
                {canEdit && (
                  <div className="flex justify-end">
                    <button type="button" onClick={() => removeLine(i)} className="text-sm text-red-600 hover:underline">Remove</button>
                  </div>
                )}
              </div>
            ))}
          </div>
        </section>

        {canEdit && (
          <div className="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-4 border-t">
            <button type="button" onClick={() => navigate('/app/inventory/issues')} className="w-full sm:w-auto px-4 py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
            <button onClick={handleSubmit} disabled={createM.isPending} className="w-full sm:w-auto px-4 py-2.5 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50">
              {createM.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        )}
      </FormCard>
    </PageContainer>
  );
}
