import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import type { Harvest } from '../../types';
import { useHarvestSuggestions } from '../../hooks/useHarvests';
import {
  harvestsApi,
  harvestMachineSuggestionToPayload,
  harvestLabourSuggestionToPayload,
  harvestTemplateLineToPayload,
  isApplicableFieldJobShareSuggestion,
  isApplicableTemplateLine,
  type HarvestMachineSuggestionRow,
  type HarvestLabourSuggestionRow,
  type HarvestShareTemplateLineRow,
  type HarvestSuggestionConfidence,
} from '../../api/harvests';
import { Modal } from '../Modal';

type Props = {
  harvest: Harvest;
  harvestId: string;
  canEdit: boolean;
};

const ROLE_LABEL: Record<string, string> = {
  OWNER: 'Owner retained',
  MACHINE: 'Machine',
  LABOUR: 'Labour',
  LANDLORD: 'Landlord',
  CONTRACTOR: 'Contractor',
};

function errMessage(e: unknown): string {
  return (
    (e as { response?: { data?: { error?: string; message?: string; errors?: Record<string, unknown> } } })?.response
      ?.data?.message ||
    (e as { response?: { data?: { error?: string } } })?.response?.data?.error ||
    'Request failed'
  );
}

function nextSortOrderStart(harvest: Harvest): number {
  const lines = harvest.share_lines ?? [];
  return lines.reduce((m, l) => Math.max(m, l.sort_order ?? 0), 0) + 1;
}

function ConfidenceBadge({ level }: { level: HarvestSuggestionConfidence }) {
  const styles: Record<HarvestSuggestionConfidence, string> = {
    HIGH: 'bg-emerald-100 text-emerald-900 border-emerald-200',
    MEDIUM: 'bg-amber-100 text-amber-950 border-amber-200',
    LOW: 'bg-gray-100 text-gray-800 border-gray-200',
  };
  const label: Record<HarvestSuggestionConfidence, string> = {
    HIGH: 'High — includes agreements or strong data match',
    MEDIUM: 'Medium — partial match',
    LOW: 'Low — limited data',
  };
  return (
    <span
      className={`inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-medium ${styles[level]}`}
      title={label[level]}
    >
      Confidence: {level}
    </span>
  );
}

function AgreementSourceBadge() {
  return (
    <span className="inline-flex items-center rounded-md border border-[#1F6F5C]/40 bg-[#EEF5F3] px-2 py-0.5 text-xs font-medium text-[#145044]">
      Based on agreement
    </span>
  );
}

function isAgreementSuggestion(s: HarvestMachineSuggestionRow | HarvestLabourSuggestionRow): boolean {
  return s.suggestion_source === 'AGREEMENT';
}

function machineRowKey(s: HarvestMachineSuggestionRow): string {
  return s.field_job_machine_id ?? s.agreement_id ?? s.machine_id;
}

function labourRowKey(s: HarvestLabourSuggestionRow): string {
  return s.field_job_labour_id ?? s.agreement_id ?? s.worker_id;
}

function formatShareRule(
  basis: string,
  s: {
    suggested_share_value?: string | null;
    suggested_ratio_numerator?: string | null;
    suggested_ratio_denominator?: string | null;
  }
): string {
  if (basis === 'PERCENT') {
    return `${s.suggested_share_value ?? '—'}%`;
  }
  if (basis === 'FIXED_QTY') {
    return `Fixed ${s.suggested_share_value ?? '—'}`;
  }
  return `${s.suggested_ratio_numerator ?? '—'}:${s.suggested_ratio_denominator ?? '—'}`;
}

export function HarvestSuggestedSharesPanel({ harvest, harvestId, canEdit }: Props) {
  const isDraft = harvest.status === 'DRAFT';
  const qc = useQueryClient();
  const { data, isLoading, isError, error, refetch } = useHarvestSuggestions(harvestId, isDraft);

  const [busy, setBusy] = useState(false);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [applyBusy, setApplyBusy] = useState(false);
  const [showOverwriteModal, setShowOverwriteModal] = useState(false);

  const hasHarvestLines = (harvest.lines?.length ?? 0) > 0;
  const canApply = isDraft && canEdit && hasHarvestLines;
  const existingShareCount = harvest.share_lines?.length ?? 0;

  const hasAnythingToApply = useMemo(() => {
    if (!data) {
      return false;
    }
    const m = data.machine_suggestions.some(isApplicableFieldJobShareSuggestion);
    const l = data.labour_suggestions.some(isApplicableFieldJobShareSuggestion);
    const t = (data.share_templates[0]?.lines ?? []).some(isApplicableTemplateLine);
    return m || l || t;
  }, [data]);

  const { machineFromAgreement, machineFromFieldJob } = useMemo(() => {
    const ms = data?.machine_suggestions ?? [];
    return {
      machineFromAgreement: ms.filter(isAgreementSuggestion),
      machineFromFieldJob: ms.filter((s) => !isAgreementSuggestion(s)),
    };
  }, [data]);

  const { labourFromAgreement, labourFromFieldJob } = useMemo(() => {
    const ls = data?.labour_suggestions ?? [];
    return {
      labourFromAgreement: ls.filter(isAgreementSuggestion),
      labourFromFieldJob: ls.filter((s) => !isAgreementSuggestion(s)),
    };
  }, [data]);

  const tpl = data?.share_templates[0];
  const templateIsAgreement = tpl?.template_source === 'AGREEMENT';

  const invalidateHarvest = async () => {
    await qc.invalidateQueries({ queryKey: ['harvests', harvestId] });
    await qc.invalidateQueries({ queryKey: ['harvests', harvestId, 'share-preview'] });
    await qc.invalidateQueries({ queryKey: ['harvests', harvestId, 'suggestions'] });
  };

  const runApplyAgreements = async (overwrite: boolean) => {
    setApplyBusy(true);
    setShowOverwriteModal(false);
    try {
      const res = await harvestsApi.applyAgreements(harvestId, { overwrite });
      await invalidateHarvest();
      if (res.created_count === 0) {
        toast(res.message || 'No agreement lines were created.');
      } else {
        toast.success(
          `Created ${res.created_count} share line(s) from agreements${res.replaced_existing ? ' (replaced existing)' : ''}.`
        );
      }
    } catch (e) {
      toast.error(errMessage(e));
    } finally {
      setApplyBusy(false);
    }
  };

  const runSingleApply = async (fn: (sortOrder: number) => Promise<unknown>) => {
    setBusy(true);
    try {
      const start = nextSortOrderStart(harvest);
      await fn(start);
      await invalidateHarvest();
      toast.success('Share line added from suggestion');
    } catch (e) {
      toast.error(errMessage(e));
    } finally {
      setBusy(false);
      setBusyKey(null);
    }
  };

  const handleApplyAll = async () => {
    if (!canApply || !data || !hasAnythingToApply) {
      return;
    }
    setBusy(true);
    try {
      let order = nextSortOrderStart(harvest);
      for (const s of data.machine_suggestions) {
        if (!isApplicableFieldJobShareSuggestion(s)) {
          continue;
        }
        await harvestsApi.addShareLine(harvestId, harvestMachineSuggestionToPayload(s, order));
        order += 1;
      }
      for (const s of data.labour_suggestions) {
        if (!isApplicableFieldJobShareSuggestion(s)) {
          continue;
        }
        await harvestsApi.addShareLine(harvestId, harvestLabourSuggestionToPayload(s, order));
        order += 1;
      }
      const block = data.share_templates[0];
      if (block) {
        for (const line of block.lines) {
          if (!isApplicableTemplateLine(line)) {
            continue;
          }
          await harvestsApi.addShareLine(harvestId, harvestTemplateLineToPayload(line, order));
          order += 1;
        }
      }
      await invalidateHarvest();
      toast.success('Applied all suggestions');
    } catch (e) {
      toast.error(errMessage(e));
    } finally {
      setBusy(false);
    }
  };

  const applyOneMachine = (s: HarvestMachineSuggestionRow) => {
    if (!canApply || !isApplicableFieldJobShareSuggestion(s)) {
      return;
    }
    setBusyKey(`m:${machineRowKey(s)}`);
    void runSingleApply((start) => harvestsApi.addShareLine(harvestId, harvestMachineSuggestionToPayload(s, start)));
  };

  const applyOneLabour = (s: HarvestLabourSuggestionRow) => {
    if (!canApply || !isApplicableFieldJobShareSuggestion(s)) {
      return;
    }
    setBusyKey(`l:${labourRowKey(s)}`);
    void runSingleApply((start) => harvestsApi.addShareLine(harvestId, harvestLabourSuggestionToPayload(s, start)));
  };

  const applyOneTemplateLine = (line: HarvestShareTemplateLineRow, idx: number) => {
    if (!canApply || !isApplicableTemplateLine(line)) {
      return;
    }
    setBusyKey(`t:${idx}`);
    void runSingleApply((start) => harvestsApi.addShareLine(harvestId, harvestTemplateLineToPayload(line, start)));
  };

  const applyTemplateBlock = (lines: HarvestShareTemplateLineRow[]) => {
    if (!canApply) {
      return;
    }
    const applicable = lines.filter(isApplicableTemplateLine);
    if (applicable.length === 0) {
      return;
    }
    setBusy(true);
    void (async () => {
      try {
        let order = nextSortOrderStart(harvest);
        for (const line of applicable) {
          await harvestsApi.addShareLine(harvestId, harvestTemplateLineToPayload(line, order));
          order += 1;
        }
        await invalidateHarvest();
        toast.success(`Applied ${applicable.length} template line(s)`);
      } catch (e) {
        toast.error(errMessage(e));
      } finally {
        setBusy(false);
      }
    })();
  };

  if (!isDraft) {
    return null;
  }

  return (
    <div className="bg-white rounded-lg shadow p-6 space-y-4 border border-dashed border-[#1F6F5C]/30">
      <Modal
        isOpen={showOverwriteModal}
        onClose={() => setShowOverwriteModal(false)}
        title="Replace existing share lines?"
      >
        <p className="text-sm text-gray-700">
          This harvest already has {existingShareCount} share line(s). Applying agreements will remove them and create
          new lines from active agreements. Nothing is posted until you use <strong>Post harvest</strong>.
        </p>
        <div className="mt-4 flex flex-wrap gap-2 justify-end">
          <button
            type="button"
            className="px-3 py-1.5 border border-gray-300 rounded text-sm"
            onClick={() => setShowOverwriteModal(false)}
          >
            Cancel
          </button>
          <button
            type="button"
            className="px-3 py-1.5 bg-[#1F6F5C] text-white rounded text-sm"
            onClick={() => void runApplyAgreements(true)}
          >
            Replace and apply
          </button>
        </div>
      </Modal>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="font-semibold text-gray-900">Suggested shares</h3>
          <p className="text-sm text-gray-600 mt-1">
            Suggestions combine{' '}
            <Link to="/app/crop-ops/agreements" className="text-[#1F6F5C] font-medium underline underline-offset-2">
              agreements
            </Link>
            , linked field jobs, and (when no agreement template) the last posted harvest for this field cycle. Nothing
            is added until you apply — you can edit lines afterwards in <strong>Output shares</strong> below.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {data && <ConfidenceBadge level={data.confidence} />}
          {canEdit && (
            <button
              type="button"
              onClick={() => refetch()}
              disabled={isLoading}
              className="text-sm px-3 py-1.5 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50"
            >
              {isLoading ? 'Loading…' : 'Refresh'}
            </button>
          )}
        </div>
      </div>

      {isDraft && canEdit && (
        <div className="rounded-lg border border-[#1F6F5C]/25 bg-[#F7FBFA] px-4 py-3 space-y-2">
          <h4 className="text-sm font-semibold text-[#145044]">Apply agreements</h4>
          <p className="text-sm text-gray-700">
            Creates draft share lines from the agreements that match this harvest (date, field cycle, crop cycle). This
            does <strong>not</strong> post the harvest — you can still edit or delete lines afterwards.
          </p>
          <button
            type="button"
            disabled={applyBusy}
            onClick={() => {
              if (existingShareCount > 0) {
                setShowOverwriteModal(true);
              } else {
                void runApplyAgreements(false);
              }
            }}
            className="inline-flex items-center px-4 py-2 bg-[#1F6F5C] text-white rounded text-sm font-medium disabled:opacity-50"
          >
            {applyBusy ? 'Applying…' : 'Apply agreements'}
          </button>
        </div>
      )}

      {isLoading && <p className="text-sm text-gray-500">Loading suggestions…</p>}
      {isError && (
        <div className="rounded-md bg-red-50 text-red-800 text-sm px-3 py-2">
          {(error as Error)?.message || 'Could not load suggestions.'}
        </div>
      )}

      {!hasHarvestLines && (
        <p className="text-sm text-amber-800 rounded-md bg-amber-50 px-3 py-2 border border-amber-100">
          Add at least one harvest line above before applying row-level suggestions — share lines attach to the harvest
          output. You can still use <strong>Apply agreements</strong> above; optional inventory links use the first
          harvest line when present.
        </p>
      )}

      {data && canEdit && (
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => void handleApplyAll()}
            disabled={!canApply || busy || !hasAnythingToApply}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded text-sm disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {busy && !busyKey ? 'Applying…' : 'Apply all suggestions'}
          </button>
        </div>
      )}

      {data && machineFromAgreement.length > 0 && (
        <div>
          <h4 className="text-sm font-medium text-gray-800 mb-2">Machine share (from agreements)</h4>
          <div className="overflow-x-auto border rounded-lg">
            <table className="min-w-full text-sm">
              <thead className="bg-[#E6ECEA] text-left text-xs text-gray-600 uppercase tracking-wide">
                <tr>
                  <th className="px-3 py-2">Source</th>
                  <th className="px-3 py-2">Machine</th>
                  <th className="px-3 py-2">Usage</th>
                  <th className="px-3 py-2">Rule</th>
                  <th className="px-3 py-2 w-28" />
                </tr>
              </thead>
              <tbody>
                {machineFromAgreement.map((s) => {
                  const ok = isApplicableFieldJobShareSuggestion(s);
                  const label = s.machine_name || s.machine_code || s.machine_id.slice(0, 8);
                  return (
                    <tr key={machineRowKey(s)} className="border-t border-gray-100">
                      <td className="px-3 py-2">
                        <AgreementSourceBadge />
                      </td>
                      <td className="px-3 py-2">{label}</td>
                      <td className="px-3 py-2 tabular-nums">
                        {s.usage_qty ?? '—'}
                        {s.meter_unit_snapshot ? ` ${s.meter_unit_snapshot}` : ''}
                      </td>
                      <td className="px-3 py-2 tabular-nums">{formatShareRule(s.suggested_share_basis, s)}</td>
                      <td className="px-3 py-2">
                        {canEdit && (
                          <button
                            type="button"
                            disabled={!canApply || !ok || busy}
                            title={!ok ? 'Cannot apply this row as-is' : 'Add as share line'}
                            onClick={() => applyOneMachine(s)}
                            className="text-[#1F6F5C] hover:underline text-sm disabled:opacity-40 disabled:no-underline"
                          >
                            {busyKey === `m:${machineRowKey(s)}` ? '…' : 'Apply'}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {data && machineFromFieldJob.length > 0 && (
        <div>
          <h4 className="text-sm font-medium text-gray-800 mb-2">Machine share (from field job usage)</h4>
          <div className="overflow-x-auto border rounded-lg">
            <table className="min-w-full text-sm">
              <thead className="bg-[#E6ECEA] text-left text-xs text-gray-600 uppercase tracking-wide">
                <tr>
                  <th className="px-3 py-2">Machine</th>
                  <th className="px-3 py-2">Usage</th>
                  <th className="px-3 py-2">Suggested ratio</th>
                  <th className="px-3 py-2 w-28" />
                </tr>
              </thead>
              <tbody>
                {machineFromFieldJob.map((s) => {
                  const ok = isApplicableFieldJobShareSuggestion(s);
                  const label = s.machine_name || s.machine_code || s.machine_id.slice(0, 8);
                  return (
                    <tr key={machineRowKey(s)} className="border-t border-gray-100">
                      <td className="px-3 py-2">{label}</td>
                      <td className="px-3 py-2 tabular-nums">
                        {s.usage_qty}
                        {s.meter_unit_snapshot ? ` ${s.meter_unit_snapshot}` : ''}
                      </td>
                      <td className="px-3 py-2 tabular-nums">
                        {s.suggested_ratio_numerator}:{s.suggested_ratio_denominator}
                        <span className="text-gray-500 text-xs ml-1">(of pool {s.pool_total_usage})</span>
                      </td>
                      <td className="px-3 py-2">
                        {canEdit && (
                          <button
                            type="button"
                            disabled={!canApply || !ok || busy}
                            title={!ok ? 'Invalid ratio pool — cannot apply' : 'Add as share line'}
                            onClick={() => applyOneMachine(s)}
                            className="text-[#1F6F5C] hover:underline text-sm disabled:opacity-40 disabled:no-underline"
                          >
                            {busyKey === `m:${machineRowKey(s)}` ? '…' : 'Apply'}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {data && labourFromAgreement.length > 0 && (
        <div>
          <h4 className="text-sm font-medium text-gray-800 mb-2">Labour share (from agreements)</h4>
          <div className="overflow-x-auto border rounded-lg">
            <table className="min-w-full text-sm">
              <thead className="bg-[#E6ECEA] text-left text-xs text-gray-600 uppercase tracking-wide">
                <tr>
                  <th className="px-3 py-2">Source</th>
                  <th className="px-3 py-2">Worker</th>
                  <th className="px-3 py-2">Units</th>
                  <th className="px-3 py-2">Rule</th>
                  <th className="px-3 py-2 w-28" />
                </tr>
              </thead>
              <tbody>
                {labourFromAgreement.map((s) => {
                  const ok = isApplicableFieldJobShareSuggestion(s);
                  return (
                    <tr key={labourRowKey(s)} className="border-t border-gray-100">
                      <td className="px-3 py-2">
                        <AgreementSourceBadge />
                      </td>
                      <td className="px-3 py-2">{s.worker_name || s.worker_id.slice(0, 8)}</td>
                      <td className="px-3 py-2 tabular-nums">{s.units ?? '—'}</td>
                      <td className="px-3 py-2 tabular-nums">{formatShareRule(s.suggested_share_basis, s)}</td>
                      <td className="px-3 py-2">
                        {canEdit && (
                          <button
                            type="button"
                            disabled={!canApply || !ok || busy}
                            title={!ok ? 'Cannot apply this row as-is' : 'Add as share line'}
                            onClick={() => applyOneLabour(s)}
                            className="text-[#1F6F5C] hover:underline text-sm disabled:opacity-40 disabled:no-underline"
                          >
                            {busyKey === `l:${labourRowKey(s)}` ? '…' : 'Apply'}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {data && labourFromFieldJob.length > 0 && (
        <div>
          <h4 className="text-sm font-medium text-gray-800 mb-2">Labour share (from field job)</h4>
          <div className="overflow-x-auto border rounded-lg">
            <table className="min-w-full text-sm">
              <thead className="bg-[#E6ECEA] text-left text-xs text-gray-600 uppercase tracking-wide">
                <tr>
                  <th className="px-3 py-2">Worker</th>
                  <th className="px-3 py-2">Units</th>
                  <th className="px-3 py-2">Suggested ratio</th>
                  <th className="px-3 py-2 w-28" />
                </tr>
              </thead>
              <tbody>
                {labourFromFieldJob.map((s) => {
                  const ok = isApplicableFieldJobShareSuggestion(s);
                  return (
                    <tr key={labourRowKey(s)} className="border-t border-gray-100">
                      <td className="px-3 py-2">{s.worker_name || s.worker_id.slice(0, 8)}</td>
                      <td className="px-3 py-2 tabular-nums">{s.units}</td>
                      <td className="px-3 py-2 tabular-nums">
                        {s.suggested_ratio_numerator}:{s.suggested_ratio_denominator}
                        <span className="text-gray-500 text-xs ml-1">(of pool {s.pool_total_units})</span>
                      </td>
                      <td className="px-3 py-2">
                        {canEdit && (
                          <button
                            type="button"
                            disabled={!canApply || !ok || busy}
                            title={!ok ? 'Invalid ratio pool — cannot apply' : 'Add as share line'}
                            onClick={() => applyOneLabour(s)}
                            className="text-[#1F6F5C] hover:underline text-sm disabled:opacity-40 disabled:no-underline"
                          >
                            {busyKey === `l:${labourRowKey(s)}` ? '…' : 'Apply'}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {data && tpl && tpl.lines.length > 0 && (
        <div>
          <div className="flex flex-wrap items-center justify-between gap-2 mb-2">
            <h4 className="text-sm font-medium text-gray-800">
              {templateIsAgreement ? (
                'Share template (from agreements)'
              ) : (
                <>
                  Share structure from previous harvest
                  {tpl.source_harvest_no && (
                    <span className="font-normal text-gray-600"> ({tpl.source_harvest_no})</span>
                  )}
                </>
              )}
            </h4>
            {canEdit && (
              <button
                type="button"
                disabled={!canApply || busy}
                onClick={() => applyTemplateBlock(tpl.lines)}
                className="text-sm px-3 py-1.5 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50"
              >
                Apply all template lines
              </button>
            )}
          </div>
          <div className="overflow-x-auto border rounded-lg">
            <table className="min-w-full text-sm">
              <thead className="bg-[#E6ECEA] text-left text-xs text-gray-600 uppercase tracking-wide">
                <tr>
                  <th className="px-3 py-2">Source</th>
                  <th className="px-3 py-2">Role</th>
                  <th className="px-3 py-2">Settlement</th>
                  <th className="px-3 py-2">Rule</th>
                  <th className="px-3 py-2 w-28" />
                </tr>
              </thead>
              <tbody>
                {tpl.lines.map((line, idx) => {
                  const ok = isApplicableTemplateLine(line);
                  const rule =
                    line.share_basis === 'PERCENT'
                      ? `${line.share_value ?? '—'}%`
                      : line.share_basis === 'RATIO'
                        ? `${line.ratio_numerator ?? '?'}:${line.ratio_denominator ?? '?'}`
                        : line.share_basis === 'FIXED_QTY'
                          ? `Fixed ${line.share_value ?? '—'}`
                          : line.share_basis === 'REMAINDER'
                            ? 'Remainder'
                            : line.share_basis;
                  const fromAgreementLine = line.suggestion_source === 'AGREEMENT' || templateIsAgreement;
                  return (
                    <tr key={`${line.sort_order ?? idx}-${idx}`} className="border-t border-gray-100">
                      <td className="px-3 py-2">
                        {fromAgreementLine ? <AgreementSourceBadge /> : <span className="text-xs text-gray-500">History</span>}
                      </td>
                      <td className="px-3 py-2">{ROLE_LABEL[line.recipient_role] ?? line.recipient_role}</td>
                      <td className="px-3 py-2">{line.settlement_mode === 'IN_KIND' ? 'In-kind' : 'Cash'}</td>
                      <td className="px-3 py-2">{rule}</td>
                      <td className="px-3 py-2">
                        {canEdit && (
                          <button
                            type="button"
                            disabled={!canApply || !ok || busy}
                            title={!ok ? 'Line cannot be applied as-is' : 'Add as share line'}
                            onClick={() => applyOneTemplateLine(line, idx)}
                            className="text-[#1F6F5C] hover:underline text-sm disabled:opacity-40 disabled:no-underline"
                          >
                            {busyKey === `t:${idx}` ? '…' : 'Apply'}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {data &&
        data.machine_suggestions.length === 0 &&
        data.labour_suggestions.length === 0 &&
        (data.share_templates.length === 0 || data.share_templates[0].lines.length === 0) && (
          <p className="text-sm text-gray-500">No suggestions for this harvest yet.</p>
        )}
    </div>
  );
}
