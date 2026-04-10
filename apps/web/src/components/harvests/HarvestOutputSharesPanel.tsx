import { useState, useEffect } from 'react';
import {
  useAddHarvestShareLine,
  useDeleteHarvestShareLine,
  useHarvestSharePreview,
  useUpdateHarvestShareLine,
} from '../../hooks/useHarvests';
import { useMachinesQuery } from '../../hooks/useMachinery';
import { useWorkers } from '../../hooks/useLabour';
import { useParties } from '../../hooks/useParties';
import { useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { HarvestShareLineModal } from './HarvestShareLineModal';
import type { Harvest, HarvestLine, HarvestShareLine, HarvestShareLinePayload } from '../../types';
import { useFormatting } from '../../hooks/useFormatting';
import { parseSnapshotNumber, shareLineHasPostedSnapshot } from '../../utils/harvestSharePosted';

const ROLE_LABEL: Record<string, string> = {
  OWNER: 'Owner retained',
  MACHINE: 'Machine',
  LABOUR: 'Labour',
  LANDLORD: 'Landlord',
  CONTRACTOR: 'Contractor',
};

function formatBasis(sl: HarvestShareLine): string {
  switch (sl.share_basis) {
    case 'PERCENT':
      return `${sl.share_value != null ? sl.share_value : '—'}%`;
    case 'FIXED_QTY':
      return `Fixed qty ${sl.share_value ?? '—'}`;
    case 'RATIO':
      return `Ratio ${sl.ratio_numerator ?? '?'}:${sl.ratio_denominator ?? '?'}`;
    case 'REMAINDER':
      return 'Remainder';
    default:
      return sl.share_basis;
  }
}

function resolveLine(harvestLines: HarvestLine[], sl: HarvestShareLine): HarvestLine | undefined {
  const id = sl.harvest_line_id;
  if (!id) return undefined;
  return harvestLines.find((l) => l.id === id) ?? sl.harvest_line ?? sl.harvestLine ?? undefined;
}

function beneficiaryName(sl: HarvestShareLine): string {
  const p = sl.beneficiary_party ?? sl.beneficiaryParty;
  return p?.name?.trim() || '';
}

function whoDetail(sl: HarvestShareLine): string {
  const role = ROLE_LABEL[sl.recipient_role] ?? sl.recipient_role;
  const ben = beneficiaryName(sl);
  const m = sl.machine?.name || sl.machine?.code;
  const w = sl.worker?.name;
  const extra = ben || m || w;
  return extra ? `${role} — ${extra}` : role;
}

type Props = {
  harvest: Harvest;
  harvestId: string;
  canEdit: boolean;
};

export function HarvestOutputSharesPanel({ harvest, harvestId, canEdit }: Props) {
  const { formatDate, formatMoney, formatNumber } = useFormatting();
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<HarvestShareLine | null>(null);
  const [previewDate, setPreviewDate] = useState(harvest.harvest_date?.slice(0, 10) || '');

  useEffect(() => {
    setPreviewDate(harvest.harvest_date?.slice(0, 10) || '');
  }, [harvest.id, harvest.harvest_date]);

  const addM = useAddHarvestShareLine();
  const updateM = useUpdateHarvestShareLine();
  const deleteM = useDeleteHarvestShareLine();
  const previewQ = useHarvestSharePreview(harvestId, previewDate);

  const { data: machines = [] } = useMachinesQuery({ status: 'ACTIVE' });
  const { data: workers = [] } = useWorkers({ is_active: true });
  const { data: parties = [] } = useParties();
  const { data: stores = [] } = useInventoryStores();
  const { data: items = [] } = useInventoryItems(true);

  const lines = harvest.lines ?? [];
  const shareLines = harvest.share_lines ?? [];
  const isDraft = harvest.status === 'DRAFT';
  const isPostedOrReversed = harvest.status === 'POSTED' || harvest.status === 'REVERSED';
  const readOnly = !isDraft || !canEdit;
  const showPostedCols = isPostedOrReversed && shareLines.some((s) => shareLineHasPostedSnapshot(s));

  const handleSave = async (payload: HarvestShareLinePayload) => {
    if (editing) {
      await updateM.mutateAsync({ id: harvestId, shareLineId: editing.id, payload });
    } else {
      await addM.mutateAsync({ id: harvestId, payload });
    }
    setModalOpen(false);
    setEditing(null);
  };

  const handleDelete = (sl: HarvestShareLine) => {
    if (!window.confirm('Remove this share line?')) return;
    deleteM.mutate({ id: harvestId, shareLineId: sl.id });
  };

  const loadPreview = () => {
    previewQ.refetch();
  };

  const saving = addM.isPending || updateM.isPending;

  return (
    <div className="bg-white rounded-lg shadow p-6 space-y-6">
      <div>
        <h3 className="font-semibold text-gray-900">Output shares</h3>
        <p className="text-sm text-gray-600 mt-1">
          {isDraft ? (
            <>
              Divide output between the farm (owner), contractors, machinery, and labour. Use{' '}
              <strong>Split preview</strong> to see indicative quantities and values from the crop cost pool before you
              post.
            </>
          ) : (
            <>
              Rules you set before posting, with <strong>posted quantities and values</strong> frozen from the server
              when the harvest was posted{harvest.status === 'REVERSED' ? ' (historical view — harvest reversed)' : ''}.
            </>
          )}
        </p>
        {isDraft && (
          <div className="mt-3 rounded-md border border-blue-100 bg-blue-50/80 px-3 py-2 text-sm text-blue-950">
            Preview uses costs recorded up to the date you pick. Posting locks the split using the same rules — nothing is
            estimated in the browser after post.
          </div>
        )}
      </div>

      {readOnly && (
        <p className="text-sm text-gray-600">
          {harvest.status === 'POSTED' && 'This harvest is posted — share lines are read-only.'}
          {harvest.status === 'REVERSED' && 'This harvest is reversed — share lines are read-only (historical rules).'}
          {!canEdit && isDraft && 'You do not have permission to edit harvests.'}
        </p>
      )}

      {harvest.status === 'REVERSED' && (
        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
          Reversed harvest: the table below shows what was posted before reversal, for traceability.
        </div>
      )}

      <div className="overflow-x-auto border rounded-lg">
        <table className="min-w-full text-sm">
          <thead className="bg-[#E6ECEA] text-left text-xs text-gray-600 uppercase tracking-wide">
            <tr>
              <th className="px-3 py-2">Line</th>
              <th className="px-3 py-2">Who</th>
              <th className="px-3 py-2">Settlement</th>
              <th className="px-3 py-2">Rule</th>
              {showPostedCols && (
                <>
                  <th className="px-3 py-2 text-right">Posted qty</th>
                  <th className="px-3 py-2 text-right" title="From posting snapshot">
                    Unit cost
                  </th>
                  <th className="px-3 py-2 text-right" title="From posting snapshot">
                    Posted value
                  </th>
                </>
              )}
              <th className="px-3 py-2">Order</th>
              {!readOnly && <th className="px-3 py-2 w-28" />}
            </tr>
          </thead>
          <tbody>
            {shareLines.length === 0 && (
              <tr>
                <td
                  colSpan={5 + (showPostedCols ? 3 : 0) + (!readOnly ? 1 : 0)}
                  className="px-3 py-4 text-gray-500"
                >
                  {isDraft
                    ? 'No share lines yet. Add lines to split output; if you add none, preview assumes everything stays with the owner.'
                    : 'No share lines were recorded on this harvest.'}
                </td>
              </tr>
            )}
            {shareLines.map((sl) => {
              const hl = resolveLine(lines, sl);
              const lineLabel = hl
                ? `${hl.item?.name ?? 'Item'} · ${hl.quantity} ${hl.uom || ''}`
                : 'All lines (harvest level)';
              const pq = parseSnapshotNumber(sl.computed_qty);
              const pu = parseSnapshotNumber(sl.computed_unit_cost_snapshot);
              const pv = parseSnapshotNumber(sl.computed_value_snapshot);
              return (
                <tr key={sl.id} className="border-t border-gray-100">
                  <td className="px-3 py-2 align-top">{lineLabel}</td>
                  <td className="px-3 py-2 align-top">{whoDetail(sl)}</td>
                  <td className="px-3 py-2 align-top">{sl.settlement_mode === 'IN_KIND' ? 'In-kind' : 'Cash'}</td>
                  <td className="px-3 py-2 align-top">{formatBasis(sl)}</td>
                  {showPostedCols && (
                    <>
                      <td className="px-3 py-2 align-top text-right tabular-nums">
                        {pq != null ? formatNumber(pq, { maximumFractionDigits: 3 }) : '—'}
                      </td>
                      <td className="px-3 py-2 align-top text-right tabular-nums text-gray-700">
                        {pu != null ? formatMoney(pu) : '—'}
                      </td>
                      <td className="px-3 py-2 align-top text-right tabular-nums">
                        {pv != null ? formatMoney(pv) : '—'}
                      </td>
                    </>
                  )}
                  <td className="px-3 py-2 align-top tabular-nums">{sl.sort_order ?? '—'}</td>
                  {!readOnly && (
                    <td className="px-3 py-2 align-top whitespace-nowrap">
                      <button
                        type="button"
                        className="text-[#1F6F5C] hover:underline mr-3"
                        onClick={() => {
                          setEditing(sl);
                          setModalOpen(true);
                        }}
                      >
                        Edit
                      </button>
                      <button type="button" className="text-red-600 hover:underline" onClick={() => handleDelete(sl)}>
                        Remove
                      </button>
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {isDraft && canEdit && lines.length > 0 && (
        <button
          type="button"
          onClick={() => {
            setEditing(null);
            setModalOpen(true);
          }}
          className="px-4 py-2 bg-[#1F6F5C] text-white rounded text-sm"
        >
          Add share line
        </button>
      )}

      {isDraft && lines.length === 0 && canEdit && (
        <p className="text-sm text-amber-800">Add at least one harvest line above before defining output shares.</p>
      )}

      <div className="border-t border-gray-200 pt-6">
        <h4 className="font-medium text-gray-900 mb-2">{isDraft ? 'Split preview' : 'Posted split (read-only)'}</h4>
        {isDraft ? (
          <>
            <p className="text-sm text-gray-600 mb-3">
              Shows how quantities and indicative values would fall out using costs recorded for the crop up to the date
              you pick. <span className="font-medium">Owner retained</span> is what stays with the farm after other
              buckets take their share (including any implicit owner line the system adds when you do not define one).
            </p>

            <div className="flex flex-wrap gap-3 items-end mb-3">
              <label className="text-sm">
                <span className="block text-gray-600 mb-1">Cost up to</span>
                <input
                  type="date"
                  value={previewDate}
                  onChange={(e) => setPreviewDate(e.target.value)}
                  className="px-3 py-2 border rounded"
                />
              </label>
              <button
                type="button"
                onClick={loadPreview}
                disabled={previewQ.isFetching || !previewDate}
                className="px-4 py-2 bg-gray-800 text-white rounded text-sm disabled:opacity-50"
              >
                {previewQ.isFetching ? 'Loading…' : 'Refresh preview'}
              </button>
            </div>

            {previewQ.isError && (
              <div className="rounded-md bg-red-50 text-red-800 text-sm px-3 py-2 mb-3">
                {(previewQ.error as Error)?.message || 'Preview could not be loaded.'}
              </div>
            )}

            {previewQ.data && (
              <div className="space-y-4 text-sm">
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                  <div className="rounded border border-gray-100 bg-gray-50 px-3 py-2">
                    <div className="text-xs text-gray-500">Total harvest qty</div>
                    <div className="font-semibold tabular-nums">{previewQ.data.totals.harvest_quantity}</div>
                  </div>
                  <div className="rounded border border-gray-100 bg-gray-50 px-3 py-2">
                    <div className="text-xs text-gray-500">Indicative cost pool</div>
                    <div className="font-semibold tabular-nums">{previewQ.data.total_wip_cost}</div>
                  </div>
                  <div className="rounded border border-gray-100 bg-gray-50 px-3 py-2">
                    <div className="text-xs text-gray-500">Owner retained qty</div>
                    <div className="font-semibold tabular-nums">{previewQ.data.owner_retained.quantity}</div>
                  </div>
                  <div className="rounded border border-gray-100 bg-gray-50 px-3 py-2">
                    <div className="text-xs text-gray-500">Owner retained value (indicative)</div>
                    <div className="font-semibold tabular-nums">{previewQ.data.owner_retained.provisional_value}</div>
                  </div>
                </div>

                {previewQ.data.warnings?.length > 0 && (
                  <div className="rounded-md bg-amber-50 text-amber-950 px-3 py-2 space-y-1">
                    <div className="font-medium">Heads-up</div>
                    <ul className="list-disc list-inside">
                      {previewQ.data.warnings.map((w, i) => (
                        <li key={i}>{w}</li>
                      ))}
                    </ul>
                  </div>
                )}

                <div className="overflow-x-auto border rounded-lg">
                  <table className="min-w-full">
                    <thead className="bg-[#E6ECEA] text-xs text-gray-600">
                      <tr>
                        <th className="px-3 py-2 text-left">Recipient</th>
                        <th className="px-3 py-2 text-left">Qty</th>
                        <th className="px-3 py-2 text-left">Indicative value</th>
                        <th className="px-3 py-2 text-left">Notes</th>
                      </tr>
                    </thead>
                    <tbody>
                      {previewQ.data.share_buckets.map((b, idx) => (
                        <tr key={`${b.share_line_id ?? 'imp'}-${idx}`} className="border-t border-gray-100">
                          <td className="px-3 py-2">
                            {b.implicit_owner ? (
                              <span>Owner retained (implicit)</span>
                            ) : (
                              <span>
                                {ROLE_LABEL[b.recipient_role] ?? b.recipient_role}
                                {b.settlement_mode === 'IN_KIND' ? ' · In-kind' : b.settlement_mode ? ' · Cash' : ''}
                              </span>
                            )}
                          </td>
                          <td className="px-3 py-2 tabular-nums">{b.computed_qty}</td>
                          <td className="px-3 py-2 tabular-nums">{b.provisional_value}</td>
                          <td className="px-3 py-2 text-gray-500 text-xs">
                            {b.implicit_owner
                              ? 'Leftover quantity after other buckets'
                              : `Unit basis ${b.provisional_unit_cost}`}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <p className="text-xs text-gray-500">
                  Preview date used: {formatDate(previewQ.data.posting_date_used, { variant: 'medium' })} · Sum of bucket
                  values: {previewQ.data.totals.sum_bucket_value} (matches pool when rounding allows).
                </p>
              </div>
            )}

            {!previewQ.data && !previewQ.isError && !previewQ.isFetching && (
              <p className="text-gray-500 text-sm">Choose a date and click refresh to load the preview.</p>
            )}
          </>
        ) : (
          <p className="text-sm text-gray-600">
            {shareLines.some((s) => shareLineHasPostedSnapshot(s))
              ? 'Use the table above for posted quantity and value per line. The summary card on this page groups buckets for a quick view.'
              : 'This harvest was posted without share-line snapshots (no output split, or legacy post).'}
          </p>
        )}
      </div>

      <HarvestShareLineModal
        isOpen={modalOpen}
        onClose={() => {
          setModalOpen(false);
          setEditing(null);
        }}
        title={editing ? 'Edit share line' : 'Add share line'}
        harvestLines={lines}
        machines={machines}
        workers={workers}
        parties={parties}
        stores={stores}
        items={items}
        initialLine={editing}
        saving={saving}
        onSubmit={handleSave}
      />
    </div>
  );
}
