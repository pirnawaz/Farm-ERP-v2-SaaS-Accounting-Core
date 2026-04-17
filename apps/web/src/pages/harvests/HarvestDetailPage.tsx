import { useState, useEffect, useMemo } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useHarvest,
  useUpdateHarvest,
  useAddHarvestLine,
  useUpdateHarvestLine,
  useDeleteHarvestLine,
  usePostHarvest,
  useReverseHarvest,
} from '../../hooks/useHarvests';
import { useProjects } from '../../hooks/useProjects';
import { useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import type { UpdateHarvestPayload } from '../../types';
import { Term } from '../../components/Term';
import { term } from '../../config/terminology';
import { formatItemDisplayName } from '../../utils/formatItemDisplay';
import { PostingStatusBadge } from '../../utils/postingStatusDisplay';
import { HarvestOutputSharesPanel } from '../../components/harvests/HarvestOutputSharesPanel';
import { HarvestSuggestedSharesPanel } from '../../components/harvests/HarvestSuggestedSharesPanel';
import { TraceabilityPanel } from '../../components/traceability/TraceabilityPanel';
import { HarvestSharePostedSummaryCard } from '../../components/harvests/HarvestSharePostedSummaryCard';
import { HarvestEconomicsCard } from '../../components/harvests/HarvestEconomicsCard';
import { PrimaryWorkflowBanner } from '../../components/workflow/PrimaryWorkflowBanner';
import { DuplicateWorkflowRiskCallout } from '../../components/workflow/DuplicateWorkflowRiskCallout';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';

type HarvestLineForm = { inventory_item_id: string; store_id: string; quantity: string; uom: string; notes: string };

export default function HarvestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: harvest, isLoading } = useHarvest(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/harvests';

  const updateM = useUpdateHarvest();
  const addLineM = useAddHarvestLine();
  const updateLineM = useUpdateHarvestLine();
  const deleteLineM = useDeleteHarvestLine();
  const postM = usePostHarvest();
  const reverseM = useReverseHarvest();
  const { data: projectsForCrop } = useProjects(harvest?.crop_cycle_id || undefined);
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();
  const { formatDate, formatDateTime } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversalDate, setReversalDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');

  const [harvest_no, setHarvestNo] = useState('');
  const [project_id, setProjectId] = useState('');
  const [harvest_date, setHarvestDate] = useState('');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<HarvestLineForm[]>([]);

  useEffect(() => {
    if (harvest) {
      setHarvestNo(harvest.harvest_no || '');
      setProjectId(harvest.project_id || '');
      setHarvestDate(harvest.harvest_date);
      setNotes(harvest.notes || '');
      setLines((harvest.lines || []).map((l) => ({
        inventory_item_id: l.inventory_item_id,
        store_id: l.store_id,
        quantity: String(l.quantity),
        uom: l.uom || '',
        notes: l.notes || '',
      })));
      if (!showPostModal && !showReverseModal) {
        setPostingDate(new Date().toISOString().split('T')[0]);
        setReversalDate(new Date().toISOString().split('T')[0]);
      }
    }
  }, [harvest, showPostModal, showReverseModal]);

  const isDraft = harvest?.status === 'DRAFT';
  const isPosted = harvest?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const harvestLinesPositive = useMemo(() => {
    const list = harvest?.lines ?? [];
    return list.some((l) => parseFloat(String(l.quantity ?? 0)) > 0);
  }, [harvest?.lines]);

  const canOpenHarvestRecord = Boolean(
    harvest?.status === 'DRAFT' && harvest?.project_id && harvestLinesPositive
  );
  const canConfirmHarvestPost = Boolean(canOpenHarvestRecord && postingDate);

  const updateLine = (i: number, f: Partial<HarvestLineForm>) => {
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));
    if (isDraft && harvest?.lines?.[i]) {
      const line = harvest.lines[i];
      const payload: any = {};
      if (f.inventory_item_id !== undefined) payload.inventory_item_id = f.inventory_item_id;
      if (f.store_id !== undefined) payload.store_id = f.store_id;
      if (f.quantity !== undefined) payload.quantity = parseFloat(f.quantity);
      if (f.uom !== undefined) payload.uom = f.uom || undefined;
      if (f.notes !== undefined) payload.notes = f.notes || undefined;
      updateLineM.mutate({ id: id!, lineId: line.id, payload });
    }
  };

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
    const payload: UpdateHarvestPayload = {
      harvest_no: harvest_no || undefined,
      project_id: project_id || undefined,
      harvest_date,
      notes: notes || undefined,
    };
    await updateM.mutateAsync({ id, payload });
  };

  const handleAddLine = async () => {
    if (!id || !isDraft) return;
    const newLine = lines[lines.length - 1];
    if (newLine.inventory_item_id && newLine.store_id && parseFloat(newLine.quantity) > 0) {
      await addLineM.mutateAsync({
        id,
        payload: {
          inventory_item_id: newLine.inventory_item_id,
          store_id: newLine.store_id,
          quantity: parseFloat(newLine.quantity),
          uom: newLine.uom || undefined,
          notes: newLine.notes || undefined,
        },
      });
    }
  };

  const handlePost = async () => {
    if (!id || !canConfirmHarvestPost) return;
    try {
      await postM.mutateAsync({ id, payload: { posting_date: postingDate } });
      setShowPostModal(false);
      postM.reset();
    } catch {
      /* OperatorErrorCallout */
    }
  };

  const canConfirmHarvestReverse = Boolean(id && reversalDate);

  const handleReverse = async () => {
    if (!canConfirmHarvestReverse) return;
    try {
      await reverseM.mutateAsync({ id: id!, payload: { reversal_date: reversalDate, reason: reverseReason || undefined } });
      setShowReverseModal(false);
      setReverseReason('');
      reverseM.reset();
    } catch {
      /* OperatorErrorCallout */
    }
  };

  const totalQty = (harvest?.lines || []).reduce((s, l) => s + parseFloat(String(l.quantity || 0)), 0);

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!harvest) return <div>Harvest not found.</div>;

  return (
    <div className="space-y-6">
      <PageHeader
        title={harvest.harvest_no || `Harvest ${harvest.id.slice(0, 8)}`}
        description="Quantities harvested against crop and field cycles, with store lines and share splits."
        helper="Use harvest share lines for who gets what—this replaces manual settlement entries for shared output. Review before posting."
        backTo={backTo}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops Overview', to: '/app/crop-ops' },
          { label: 'Harvests', to: '/app/harvests' },
          { label: harvest.harvest_no || 'Detail' },
        ]}
      />

      <PrimaryWorkflowBanner variant="harvest" />

      <TraceabilityPanel traceability={harvest.traceability} />

      <DuplicateWorkflowRiskCallout context="harvest" traceability={harvest.traceability} />

      <div className="bg-white rounded-lg shadow p-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Harvest no.</dt><dd className="font-medium">{harvest.harvest_no || '—'}</dd></div>
          <div><dt className="text-sm text-gray-500">Harvest date</dt><dd className="tabular-nums">{formatDate(harvest.harvest_date, { variant: 'medium' })}</dd></div>
          <div><dt className="text-sm text-gray-500">Crop cycle</dt><dd>{harvest.crop_cycle?.name || harvest.crop_cycle_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Field cycle</dt><dd>{harvest.project?.name ?? '—'}</dd></div>
          <div><dt className="text-sm text-gray-500">Land parcel</dt><dd>{harvest.land_parcel?.name || harvest.land_parcel_id || '—'}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><PostingStatusBadge status={harvest.status} /></dd>
          </div>
          {harvest.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500"><Term k="postingGroup" showHint /></dt>
              <dd><Link to={`/app/posting-groups/${harvest.posting_group_id}`} className="text-[#1F6F5C]">{harvest.posting_group_id}</Link></dd>
            </div>
          )}
          {harvest.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{formatDate(harvest.posting_date)}</dd></div>}
          {harvest.posted_at && (
            <div>
              <dt className="text-sm text-gray-500">Posted at</dt>
              <dd className="tabular-nums">{formatDateTime(harvest.posted_at)}</dd>
            </div>
          )}
          {harvest.status === 'REVERSED' && harvest.reversed_at && (
            <div>
              <dt className="text-sm text-gray-500">Reversed at</dt>
              <dd className="tabular-nums">{formatDateTime(harvest.reversed_at)}</dd>
            </div>
          )}
          {harvest.reversal_posting_group_id && harvest.status === 'REVERSED' && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Reversal posting group</dt>
              <dd>
                <Link to={`/app/posting-groups/${harvest.reversal_posting_group_id}`} className="text-[#1F6F5C]">
                  {harvest.reversal_posting_group_id}
                </Link>
              </dd>
            </div>
          )}
          {harvest.notes && <div className="md:col-span-2"><dt className="text-sm text-gray-500">Notes</dt><dd>{harvest.notes}</dd></div>}
        </dl>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <h3 className="font-medium mb-4">Lines</h3>
        <div className="overflow-x-auto">
        <table className="min-w-full border">
          <thead className="bg-[#E6ECEA]">
            <tr>
              <th className="px-3 py-2 text-left text-xs text-gray-500">Item</th>
              <th className="px-3 py-2 text-left text-xs text-gray-500">Store</th>
              <th className="px-3 py-2 text-left text-xs text-gray-500">Quantity</th>
              <th className="px-3 py-2 text-left text-xs text-gray-500">UOM</th>
              {isDraft && canEdit && <th className="w-20" />}
            </tr>
          </thead>
          <tbody>
            {harvest.lines?.map((l) => (
              <tr key={l.id}>
                <td className="px-3 py-2">{formatItemDisplayName(l.item)}</td>
                <td>{l.store?.name || l.store_id}</td>
                <td>{l.quantity}</td>
                <td>{l.uom || '—'}</td>
                {isDraft && canEdit && (
                  <td>
                    <button onClick={() => deleteLineM.mutate({ id: id!, lineId: l.id })} className="text-red-600 text-sm hover:underline">
                      Delete
                    </button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
        </div>
        <p className="mt-2 font-medium">Total Quantity: {totalQty.toFixed(3)}</p>
      </div>

      {isPosted && id && <HarvestEconomicsCard harvestId={id} />}

      {(isPosted || harvest.status === 'REVERSED') && <HarvestSharePostedSummaryCard harvest={harvest} />}

      {isDraft && <HarvestSuggestedSharesPanel harvest={harvest} harvestId={id!} canEdit={canEdit} />}

      <HarvestOutputSharesPanel harvest={harvest} harvestId={id!} canEdit={canEdit} />

      {isDraft && canEdit && (
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-sm font-semibold text-gray-900 mb-4">Edit draft</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Harvest No">
              <input value={harvest_no} onChange={(e) => setHarvestNo(e.target.value)} className="w-full px-3 py-2 border rounded" />
            </FormField>
            <FormField label="Harvest Date">
              <input type="date" value={harvest_date} onChange={(e) => setHarvestDate(e.target.value)} className="w-full px-3 py-2 border rounded" />
            </FormField>
            <FormField label="Project">
              <select value={project_id} onChange={(e) => setProjectId(e.target.value)} className="w-full px-3 py-2 border rounded">
                <option value="">Select project</option>
                {(projectsForCrop ?? []).map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </FormField>
            <div className="md:col-span-2">
              <FormField label="Notes">
                <textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} />
              </FormField>
            </div>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2">
              <h4 className="font-medium">Add Line</h4>
            </div>
            <div className="flex flex-wrap gap-2 items-start border p-2 rounded">
              <select
                value={lines[lines.length - 1]?.inventory_item_id || ''}
                onChange={(e) => updateLine(lines.length - 1, { inventory_item_id: e.target.value })}
                className="flex-1 px-2 py-1 border rounded text-sm"
              >
                <option value="">Item</option>
                {items?.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
              </select>
              <select
                value={lines[lines.length - 1]?.store_id || ''}
                onChange={(e) => updateLine(lines.length - 1, { store_id: e.target.value })}
                className="flex-1 px-2 py-1 border rounded text-sm"
              >
                <option value="">Store</option>
                {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
              <input
                type="number"
                step="0.001"
                value={lines[lines.length - 1]?.quantity || ''}
                onChange={(e) => updateLine(lines.length - 1, { quantity: e.target.value })}
                className="w-24 px-2 py-1 border rounded text-sm"
                placeholder="Qty"
              />
              <input
                type="text"
                value={lines[lines.length - 1]?.uom || ''}
                onChange={(e) => updateLine(lines.length - 1, { uom: e.target.value })}
                className="w-20 px-2 py-1 border rounded text-sm"
                placeholder="UOM"
              />
              <button type="button" onClick={handleAddLine} className="text-[#1F6F5C] hover:underline text-sm">Add</button>
            </div>
          </div>
          <div className="flex flex-col-reverse sm:flex-row sm:flex-wrap gap-2">
            <button type="button" onClick={handleSave} disabled={updateM.isPending} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded">
              Save
            </button>
            {canPost && (
              <button
                type="button"
                onClick={() => {
                  postM.reset();
                  setShowPostModal(true);
                }}
                disabled={!canOpenHarvestRecord}
                title={
                  !canOpenHarvestRecord
                    ? 'Set field cycle and add at least one harvest line with quantity before recording.'
                    : undefined
                }
                className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded disabled:opacity-50 min-h-[44px]"
              >
                Record to accounts
              </button>
            )}
          </div>
        </div>
      )}

      {isPosted && canPost && (
        <div>
          <button
            type="button"
            onClick={() => {
              reverseM.reset();
              setShowReverseModal(true);
            }}
            className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded min-h-[44px]"
          >
            {term('reverseAction')}
          </button>
        </div>
      )}

      <Modal
        isOpen={showPostModal}
        onClose={() => {
          setShowPostModal(false);
          postM.reset();
        }}
        title="Record harvest to accounts"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This will record harvest quantities and related allocations in the accounts for the posting date below.
          </p>
          <PrePostChecklist
            items={[
              { ok: Boolean(postingDate), label: 'Posting date chosen' },
              { ok: Boolean(harvest?.project_id), label: 'Field cycle (project) set' },
              { ok: harvestLinesPositive, label: 'At least one line with quantity > 0' },
            ]}
            blockingHint={!canConfirmHarvestPost ? 'Complete required fields before recording.' : undefined}
          />
          <OperatorErrorCallout error={postM.isError ? formatOperatorError(postM.error) : null} />
          <FormField label="Posting date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => {
                setShowPostModal(false);
                postM.reset();
              }}
              className="w-full sm:w-auto px-4 py-2 border rounded min-h-[44px]"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handlePost}
              disabled={postM.isPending || !canConfirmHarvestPost}
              className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded disabled:opacity-50 min-h-[44px]"
            >
              {postM.isPending ? 'Recording…' : 'Confirm'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showReverseModal}
        onClose={() => {
          setShowReverseModal(false);
          setReverseReason('');
          reverseM.reset();
        }}
        title={term('reverseAction')}
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This reverses the posted harvest in the accounts as of the reversal date below. Cancel if you are not ready.
          </p>
          <PrePostChecklist
            items={[
              { ok: Boolean(reversalDate), label: 'Reversal date chosen' },
            ]}
            blockingHint={!reversalDate ? 'Choose a reversal date before confirming.' : undefined}
          />
          <OperatorErrorCallout error={reverseM.isError ? formatOperatorError(reverseM.error) : null} />
          <FormField label="Reversal date" required>
            <input
              type="date"
              value={reversalDate}
              onChange={(e) => setReversalDate(e.target.value)}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            />
          </FormField>
          <FormField label="Reason (optional)">
            <textarea
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              rows={3}
              placeholder="Reason for reversal"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => {
                setShowReverseModal(false);
                setReverseReason('');
                reverseM.reset();
              }}
              className="w-full sm:w-auto px-4 py-2 border rounded min-h-[44px]"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleReverse}
              disabled={reverseM.isPending || !canConfirmHarvestReverse}
              className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded disabled:opacity-50 min-h-[44px]"
            >
              {reverseM.isPending ? term('reverseActionPending') : 'Confirm reverse'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
