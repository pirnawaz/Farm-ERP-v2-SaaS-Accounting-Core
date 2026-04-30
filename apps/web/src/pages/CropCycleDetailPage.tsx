import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useCropCycle, useCloseCropCycle, useReopenCropCycle } from '../hooks/useCropCycles';
import { useProjects } from '../hooks/useProjects';
import { useLandAllocations } from '../hooks/useLandAllocations';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import toast from 'react-hot-toast';
import { cropCyclesApi } from '../api/cropCycles';
import type { CropCycleClosePreview } from '../types';
import { SetupCompletenessBadge, type SetupCompleteness } from '../components/SetupStatusBadge';
import { getSetupCompleteness } from '../components/setupSemantics';

function setupCompleteness(project: any): SetupCompleteness {
  return getSetupCompleteness(project);
}

export default function CropCycleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: cycle, isLoading } = useCropCycle(id || '');
  const { data: projects } = useProjects(id || undefined);
  const { data: allocations } = useLandAllocations(id || undefined);
  const closeMutation = useCloseCropCycle();
  const reopenMutation = useReopenCropCycle();
  const { canCloseCropCycle } = useRole();
  const [showCloseModal, setShowCloseModal] = useState(false);
  const [closeNote, setCloseNote] = useState('');
  const [preview, setPreview] = useState<CropCycleClosePreview | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);

  const handleOpenCloseModal = async () => {
    setShowCloseModal(true);
    setCloseNote('');
    setPreview(null);
    setPreviewLoading(true);
    try {
      const data = await cropCyclesApi.closePreview(id!);
      setPreview(data);
    } catch {
      toast.error('Failed to load close preview');
    } finally {
      setPreviewLoading(false);
    }
  };

  const handleConfirmClose = async () => {
    if (!id) return;
    if (preview && preview.blocking_reasons.length > 0) {
      toast.error('Cannot close: ' + preview.blocking_reasons.join(' '));
      return;
    }
    try {
      await closeMutation.mutateAsync({ id, note: closeNote || undefined });
      toast.success('Crop cycle closed successfully');
      setShowCloseModal(false);
    } catch (error: any) {
      const msg = error?.response?.data?.message ?? error.message ?? 'Failed to close crop cycle';
      toast.error(msg);
    }
  };

  const handleReopen = async () => {
    if (!id) return;
    try {
      await reopenMutation.mutateAsync(id);
      toast.success('Crop cycle reopened successfully');
    } catch (error: any) {
      const msg = error?.response?.data?.message ?? error.message ?? 'Failed to reopen crop cycle';
      toast.error(msg);
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!cycle) {
    return <div>Crop cycle not found</div>;
  }

  const allocationRows = allocations ?? [];
  const projectRows = projects ?? [];
  const incompleteProjects = projectRows.filter((p) => setupCompleteness(p) !== 'COMPLETE');
  const parcelsAssigned = new Set(allocationRows.map((a) => a.land_parcel_id)).size;
  const totals = projectRows.reduce(
    (acc, p) => {
      const c = setupCompleteness(p);
      acc.total += 1;
      if (c === 'COMPLETE') acc.complete += 1;
      if (c === 'PARTIAL') acc.partial += 1;
      if (c === 'NOT_SET') acc.notSet += 1;
      if (!p.agreement_id) acc.missingAgreement += 1;
      if (!p.land_allocation_id) acc.missingAllocationLink += 1;
      return acc;
    },
    { total: 0, complete: 0, partial: 0, notSet: 0, missingAgreement: 0, missingAllocationLink: 0 },
  );

  const hasBlockingReasons = preview && preview.blocking_reasons.length > 0;
  const reconSummary = preview?.reconciliation_summary;
  const reconciliation = preview?.reconciliation;
  const reconFailCount = reconciliation?.counts?.fail ?? reconSummary?.fail ?? 0;
  const hasReconciliationFail = reconFailCount > 0;
  const cannotClose = hasBlockingReasons || hasReconciliationFail || closeMutation.isPending;

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/crop-cycles" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Crop Cycles
        </Link>
        <div className="flex items-center gap-3 mt-2">
          <h1 className="text-2xl font-bold text-gray-900">{cycle.name}</h1>
          <span
            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
              cycle.status === 'OPEN'
                ? 'bg-green-100 text-green-800'
                : 'bg-gray-100 text-gray-800'
            }`}
          >
            {cycle.status}
          </span>
        </div>
      </div>

      {cycle.status === 'CLOSED' && (
        <div className="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-md text-amber-800 text-sm">
          This cycle is closed. No new operational postings can be made.
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-lg shadow p-6 lg:col-span-2">
          <h2 className="text-lg font-medium text-gray-900 mb-1">Field Cycle setup progress</h2>
          <p className="text-sm text-gray-600 mb-4">
            Track how far this crop cycle is configured across parcels → allocations → field cycles → agreements.
          </p>
          <div className="mb-4 flex flex-wrap gap-2">
            <Link
              to={`/app/projects/setup?crop_cycle_id=${encodeURIComponent(cycle.id)}`}
              className="inline-flex items-center justify-center rounded-md bg-[#1F6F5C] px-3 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              Add field cycle
            </Link>
            {incompleteProjects.length > 0 && (
              <a
                href="#missing-setups"
                className="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
              >
                Complete missing setups ({incompleteProjects.length})
              </a>
            )}
          </div>
          <dl className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div className="rounded-md border border-gray-100 bg-gray-50/50 p-3">
              <dt className="text-xs font-medium text-gray-500">Total parcels assigned</dt>
              <dd className="text-xl font-semibold text-gray-900 tabular-nums">{parcelsAssigned}</dd>
            </div>
            <div className="rounded-md border border-gray-100 bg-gray-50/50 p-3">
              <dt className="text-xs font-medium text-gray-500">Total allocations</dt>
              <dd className="text-xl font-semibold text-gray-900 tabular-nums">{allocationRows.length}</dd>
            </div>
            <div className="rounded-md border border-gray-100 bg-gray-50/50 p-3">
              <dt className="text-xs font-medium text-gray-500">Total field cycles</dt>
              <dd className="text-xl font-semibold text-gray-900 tabular-nums">{projectRows.length}</dd>
            </div>
            <div className="rounded-md border border-gray-100 bg-gray-50/50 p-3">
              <dt className="text-xs font-medium text-gray-500">Fully configured</dt>
              <dd className="text-xl font-semibold text-gray-900 tabular-nums">{totals.complete}</dd>
            </div>
          </dl>
          <div className="mt-4 flex flex-wrap gap-2 items-center text-sm text-gray-700">
            <span className="font-medium text-gray-900">Setup:</span>
            <span className="inline-flex items-center gap-2">
              <SetupCompletenessBadge completeness="COMPLETE" size="sm" /> <span className="tabular-nums">{totals.complete}</span>
            </span>
            <span className="inline-flex items-center gap-2">
              <SetupCompletenessBadge completeness="PARTIAL" size="sm" /> <span className="tabular-nums">{totals.partial}</span>
            </span>
            <span className="inline-flex items-center gap-2">
              <SetupCompletenessBadge completeness="NOT_SET" size="sm" /> <span className="tabular-nums">{totals.notSet}</span>
            </span>
            <span className="text-gray-400">|</span>
            <span>
              Missing agreement: <span className="tabular-nums font-medium text-gray-900">{totals.missingAgreement}</span>
            </span>
            <span>
              Missing allocation link: <span className="tabular-nums font-medium text-gray-900">{totals.missingAllocationLink}</span>
            </span>
          </div>
        </div>

        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Cycle Information</h2>
          <dl className="space-y-2">
            <div>
              <dt className="text-sm font-medium text-gray-500">Start Date</dt>
              <dd className="text-sm text-gray-900">{cycle.start_date}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">End Date</dt>
              <dd className="text-sm text-gray-900">{cycle.end_date ?? '—'}</dd>
            </div>
            {cycle.closed_at && (
              <>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Closed At</dt>
                  <dd className="text-sm text-gray-900">{cycle.closed_at}</dd>
                </div>
                {cycle.close_note && (
                  <div>
                    <dt className="text-sm font-medium text-gray-500">Close Note</dt>
                    <dd className="text-sm text-gray-900">{cycle.close_note}</dd>
                  </div>
                )}
              </>
            )}
          </dl>
        </div>

        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Actions</h2>
          <div className="space-y-2">
            {cycle.status === 'OPEN' && canCloseCropCycle && (
              <button
                onClick={handleOpenCloseModal}
                className="w-full px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500"
              >
                Close Cycle
              </button>
            )}
            {cycle.status === 'CLOSED' && canCloseCropCycle && (
              <button
                onClick={handleReopen}
                disabled={reopenMutation.isPending}
                className="w-full px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
              >
                {reopenMutation.isPending ? 'Reopening...' : 'Reopen Cycle'}
              </button>
            )}
            <Link
              to={`/app/reports/crop-cycle-pl?crop_cycle_id=${cycle.id}`}
              className="block w-full px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-center"
            >
              View P&amp;L Report
            </Link>
          </div>
        </div>
      </div>

      {incompleteProjects.length > 0 && (
        <div id="missing-setups" className="mt-6 bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-1">Complete missing setups</h2>
          <p className="text-sm text-gray-600 mb-4">
            Jump directly into completion for field cycles that are not fully linked yet.
          </p>
          <ul className="space-y-2">
            {incompleteProjects.slice(0, 20).map((p) => (
              <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-gray-100 px-3 py-2">
                <div className="min-w-0">
                  <Link to={`/app/projects/${p.id}`} className="font-medium text-gray-900 hover:underline">
                    {p.name}
                  </Link>
                  <div className="mt-0.5 text-xs text-gray-500">
                    <SetupCompletenessBadge completeness={setupCompleteness(p)} size="sm" />
                  </div>
                </div>
                <Link
                  to={`/app/projects/setup?project_id=${encodeURIComponent(p.id)}&crop_cycle_id=${encodeURIComponent(cycle.id)}`}
                  className="inline-flex items-center justify-center rounded-md bg-[#1F6F5C] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#1a5a4a]"
                >
                  Complete setup
                </Link>
              </li>
            ))}
          </ul>
          {incompleteProjects.length > 20 && (
            <p className="mt-3 text-xs text-gray-500">Showing first 20. Use the Projects list to find the rest.</p>
          )}
        </div>
      )}

      <Modal
        isOpen={showCloseModal}
        onClose={() => setShowCloseModal(false)}
        title="Close Crop Cycle"
        size="lg"
      >
        <div className="space-y-4">
          {previewLoading && !preview && (
            <div className="flex justify-center py-4">
              <LoadingSpinner />
            </div>
          )}
          {preview && (
            <>
              <div className="space-y-2">
                <p className="text-sm font-medium text-gray-700">Checklist</p>
                <ul className="list-disc list-inside text-sm text-gray-600 space-y-1">
                  <li>Posted settlement: {preview.has_posted_settlement ? 'Yes' : 'No'}</li>
                  {(reconciliation ?? reconSummary) && (
                    <li>
                      Reconciliation: {(reconciliation?.counts ?? reconSummary)?.pass ?? 0} pass, {(reconciliation?.counts ?? reconSummary)?.warn ?? 0} warn, {(reconciliation?.counts ?? reconSummary)?.fail ?? 0} fail
                    </li>
                  )}
                </ul>
              </div>
              {reconciliation && (
                <div className="p-3 border border-gray-200 rounded-md bg-gray-50 space-y-2">
                  <p className="text-sm font-medium text-gray-700">Reconciliation</p>
                  <p className="text-xs text-gray-500">
                    Period: {reconciliation.from} to {reconciliation.to}
                  </p>
                  <div className="flex flex-wrap gap-2">
                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                      PASS {reconciliation.counts.pass}
                    </span>
                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                      WARN {reconciliation.counts.warn}
                    </span>
                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                      FAIL {reconciliation.counts.fail}
                    </span>
                  </div>
                  {reconciliation.checks.length > 0 && (
                    <ul className="mt-2 space-y-1 text-sm">
                      {reconciliation.checks.map((check, i) => (
                        <li key={i} className="flex items-start gap-2">
                          <span
                            className={`inline-flex shrink-0 items-center px-1.5 py-0.5 rounded text-xs font-medium ${
                              check.status === 'PASS'
                                ? 'bg-green-100 text-green-800'
                                : check.status === 'WARN'
                                  ? 'bg-amber-100 text-amber-800'
                                  : 'bg-red-100 text-red-800'
                            }`}
                          >
                            {check.status}
                          </span>
                          <span className="text-gray-700">
                            {check.title ?? check.key}: {check.summary}
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                  {id && reconciliation.from && reconciliation.to && (
                    <Link
                      to={`/app/reports/reconciliation-dashboard?tab=crop-cycle&crop_cycle_id=${id}&from=${reconciliation.from}&to=${reconciliation.to}`}
                      className="inline-block mt-2 text-sm text-[#1F6F5C] hover:text-[#1a5a4a] font-medium"
                    >
                      Open Reconciliation Dashboard →
                    </Link>
                  )}
                </div>
              )}
              {preview.blocking_reasons.length > 0 && (
                <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-800">
                  <p className="font-medium">Blocking:</p>
                  <ul className="list-disc list-inside mt-1">
                    {preview.blocking_reasons.map((r, i) => (
                      <li key={i}>{r}</li>
                    ))}
                  </ul>
                </div>
              )}
              {hasReconciliationFail && preview.blocking_reasons.length === 0 && (
                <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-800">
                  Reconciliation has failures. Resolve before closing.
                </div>
              )}
            </>
          )}
          <FormField label="Close note (optional)">
            <textarea
              value={closeNote}
              onChange={(e) => setCloseNote(e.target.value)}
              rows={2}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              placeholder="Optional reason or note"
            />
          </FormField>
          <div className="flex justify-end gap-3 pt-2">
            <button
              type="button"
              onClick={() => setShowCloseModal(false)}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleConfirmClose}
              disabled={cannotClose}
              className="px-4 py-2 text-sm font-medium text-white bg-orange-600 rounded-md hover:bg-orange-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {closeMutation.isPending ? 'Closing...' : 'Confirm Close'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
