import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { apiClient } from '@farm-erp/shared';
import { PageHeader } from '../../components/PageHeader';
import { FormField } from '../../components/FormField';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useLandParcels } from '../../hooks/useLandParcels';
import { useProject, useFieldCycleSetup } from '../../hooks/useProjects';
import { useLandAllocation, useLandAllocations } from '../../hooks/useLandAllocations';
import { term } from '../../config/terminology';
import type { CropCycle, LandParcel } from '../../types';

type CropOpsAgreement = {
  id: string;
  agreement_type: string;
  status: string;
  effective_from: string;
  effective_to: string | null;
  party?: { id: string; name: string } | null;
};

type AgreementAllocationRow = {
  id: string;
  allocated_area: string;
  area_uom?: string | null;
  land_parcel?: { id: string; name: string } | null;
  land_parcel_id?: string;
  starts_on: string;
  ends_on?: string | null;
  status: string;
  agreement_id: string;
};

const CROP_OPS_BASE = '/api/v1/crop-ops';

function safeNumber(v: string): number | null {
  const n = Number(v);
  if (!Number.isFinite(n)) return null;
  return n;
}

function humanizeSetupError(err: any): { message: string; fieldErrors?: Record<string, string> } {
  const data = err?.response?.data;
  const fallback = err?.message ?? 'Could not save setup. Please try again.';

  const errors = data?.errors as Record<string, string[] | string> | undefined;
  const fieldErrors: Record<string, string> = {};
  if (errors && typeof errors === 'object') {
    for (const [k, v] of Object.entries(errors)) {
      const first = Array.isArray(v) ? v[0] : v;
      if (typeof first === 'string' && first.trim()) fieldErrors[k] = first;
    }
  }

  const msg = (data?.message as string | undefined) ?? (Object.values(fieldErrors)[0] as string | undefined) ?? fallback;

  // Light copy edits for common cases.
  const friendly =
    msg === 'agreement_allocation_id must belong to the selected agreement.'
      ? 'That agreement allocation doesn’t match the selected agreement. Please pick a different allocation (or clear it).'
      : msg.includes('Crop cycle must be OPEN')
        ? 'This crop cycle is closed. Reopen the crop cycle before adding or completing setup.'
        : msg.includes('Field block is already linked to another field cycle')
          ? 'That field block name is already used by another field cycle on this parcel. Choose a different block name.'
          : msg;

  return { message: friendly, fieldErrors: Object.keys(fieldErrors).length ? fieldErrors : undefined };
}

export default function FieldCycleSetupPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const projectId = searchParams.get('project_id') || '';
  const prefillCropCycleId = searchParams.get('crop_cycle_id') || '';
  const prefillParcelId = searchParams.get('parcel_id') || '';
  const prefillAgreementId = searchParams.get('agreement_id') || '';
  const allocationId = searchParams.get('allocation_id') || '';

  const { data: cycles, isLoading: cyclesLoading } = useCropCycles();
  const { data: parcels, isLoading: parcelsLoading } = useLandParcels();
  const { data: existingProject, isError: projectLoadError } = useProject(projectId || '');
  const { data: allocation, isError: allocationLoadError } = useLandAllocation(allocationId || '');
  const setupMutation = useFieldCycleSetup();

  const [cropCycleId, setCropCycleId] = useState('');
  const [parcelId, setParcelId] = useState('');
  const [allocatedAcres, setAllocatedAcres] = useState('');
  const [fieldBlockName, setFieldBlockName] = useState('');
  const [agreementId, setAgreementId] = useState('');
  const [agreementAllocationId, setAgreementAllocationId] = useState('');
  const [projectName, setProjectName] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitError, setSubmitError] = useState<string>('');

  const { data: allocationsForCycle } = useLandAllocations(cropCycleId || undefined);

  const completionMode = !!projectId;
  const lockedContext = completionMode || !!allocationId;

  // Prefill when completing an existing setup.
  useEffect(() => {
    if (!existingProject) return;
    setCropCycleId(existingProject.crop_cycle_id ?? existingProject.crop_cycle?.id ?? '');
    const fromAllocParcelId = existingProject.land_allocation?.land_parcel_id ?? existingProject.land_allocation?.land_parcel?.id ?? '';
    setParcelId(fromAllocParcelId);
    setAllocatedAcres(existingProject.land_allocation?.allocated_acres ?? '');
    setAgreementId(existingProject.agreement_id ?? '');
    setAgreementAllocationId(existingProject.agreement_allocation_id ?? '');
    setProjectName(existingProject.name ?? '');
  }, [existingProject]);

  // Prefill from allocation context (create mode).
  useEffect(() => {
    if (!allocation || completionMode) return;
    setCropCycleId((prev) => prev || allocation.crop_cycle_id || allocation.crop_cycle?.id || '');
    setParcelId((prev) => prev || allocation.land_parcel_id || allocation.land_parcel?.id || '');
    setAllocatedAcres((prev) => prev || allocation.allocated_acres || '');
  }, [allocation, completionMode]);

  // Prefill from explicit query params (create mode).
  useEffect(() => {
    if (completionMode) return;
    if (prefillCropCycleId) setCropCycleId((prev) => prev || prefillCropCycleId);
    if (prefillParcelId) setParcelId((prev) => prev || prefillParcelId);
    if (prefillAgreementId) setAgreementId((prev) => prev || prefillAgreementId);
  }, [completionMode, prefillCropCycleId, prefillParcelId, prefillAgreementId]);

  const selectedCycle = useMemo(
    () => (cycles ?? []).find((c) => c.id === cropCycleId) ?? null,
    [cycles, cropCycleId]
  );
  const selectedParcel = useMemo(
    () => (parcels ?? []).find((p) => p.id === parcelId) ?? null,
    [parcels, parcelId]
  );

  const totalParcelAcres = useMemo(() => {
    const t = selectedParcel?.total_acres;
    if (t == null) return null;
    const n = Number(t);
    return Number.isFinite(n) ? n : null;
  }, [selectedParcel?.total_acres]);

  const alreadyAllocatedAcres = useMemo(() => {
    if (!cropCycleId || !parcelId) return null;
    const rows = allocationsForCycle ?? [];
    const sum = rows
      .filter((a: any) => (a.land_parcel_id ?? a.land_parcel?.id) === parcelId)
      .reduce((acc: number, a: any) => acc + Number(a.allocated_acres ?? 0), 0);
    if (!Number.isFinite(sum)) return null;

    // If we're opened from an allocation, treat "already allocated" as excluding this allocation
    // so Remaining reflects how much is left to allocate for a new field cycle.
    if (allocationId && allocation && (allocation.land_parcel_id ?? allocation.land_parcel?.id) === parcelId) {
      const current = Number(allocation.allocated_acres ?? 0);
      if (Number.isFinite(current)) return Math.max(0, sum - current);
    }

    return sum;
  }, [allocationsForCycle, cropCycleId, parcelId, allocationId, allocation]);

  const remainingAllocableAcres = useMemo(() => {
    if (totalParcelAcres == null || alreadyAllocatedAcres == null) return null;
    return Math.max(0, totalParcelAcres - alreadyAllocatedAcres);
  }, [totalParcelAcres, alreadyAllocatedAcres]);

  const overAllocateWarning = useMemo(() => {
    if (remainingAllocableAcres == null) return null;
    const acres = safeNumber(allocatedAcres);
    if (acres === null || acres <= 0) return null;
    return acres > remainingAllocableAcres
      ? `This exceeds the remaining allocable area (${remainingAllocableAcres.toFixed(2)} acres).`
      : null;
  }, [allocatedAcres, remainingAllocableAcres]);

  // Suggest a name (only if user hasn't typed a custom one yet).
  useEffect(() => {
    if (!selectedCycle || !selectedParcel) return;
    const acres = allocatedAcres.trim();
    if (!acres) return;
    if (projectName.trim() && (!existingProject || projectName.trim() !== existingProject.name)) return;
    setProjectName(`${selectedCycle.name} – ${selectedParcel.name} – ${acres} acres`);
  }, [selectedCycle, selectedParcel, allocatedAcres, existingProject, projectName]);

  const { data: agreements, isLoading: agreementsLoading } = useQuery({
    queryKey: ['crop-ops', 'agreements', 'land-lease', 'active'],
    queryFn: () =>
      apiClient.get<CropOpsAgreement[]>(`${CROP_OPS_BASE}/agreements?agreement_type=LAND_LEASE&status=ACTIVE`),
  });

  const { data: agreementAllocations, isLoading: allocsLoading } = useQuery({
    queryKey: ['crop-ops', 'agreement-allocations', agreementId, parcelId],
    queryFn: async () => {
      if (!agreementId) return [];
      const qp = new URLSearchParams();
      qp.set('agreement_id', agreementId);
      if (parcelId) qp.set('land_parcel_id', parcelId);
      return apiClient.get<AgreementAllocationRow[]>(`${CROP_OPS_BASE}/agreement-allocations?${qp.toString()}`);
    },
    enabled: !!agreementId,
  });

  const canSubmit = useMemo(() => {
    const acres = safeNumber(allocatedAcres);
    if (!cropCycleId || !parcelId) return false;
    if (!projectName.trim()) return false;
    if (acres === null || acres <= 0) return false;
    if (agreementAllocationId && !agreementId) return false;
    return true;
  }, [cropCycleId, parcelId, allocatedAcres, projectName, agreementId, agreementAllocationId]);

  const submitting = setupMutation.isPending;

  const handleSubmit = async () => {
    setFieldErrors({});
    setSubmitError('');

    const acres = safeNumber(allocatedAcres);
    if (acres === null || acres <= 0) {
      const msg = 'Allocated area is required and must be a positive number.';
      setFieldErrors((prev) => ({ ...prev, allocated_acres: msg }));
      toast.error(msg);
      return;
    }
    if (!cropCycleId) {
      const msg = 'Select a crop cycle.';
      setFieldErrors((prev) => ({ ...prev, crop_cycle_id: msg }));
      toast.error(msg);
      return;
    }
    if (!parcelId) {
      const msg = 'Select a parcel.';
      setFieldErrors((prev) => ({ ...prev, land_parcel_id: msg }));
      toast.error(msg);
      return;
    }
    if (!projectName.trim()) {
      const msg = `${term('fieldCycle')} name is required.`;
      setFieldErrors((prev) => ({ ...prev, project_name: msg }));
      toast.error(msg);
      return;
    }
    if (agreementAllocationId && !agreementId) {
      const msg = 'Select an agreement before choosing an agreement allocation.';
      setFieldErrors((prev) => ({ ...prev, agreement_id: msg }));
      toast.error(msg);
      return;
    }

    try {
      toast.loading('Saving setup…', { id: 'setup-save' });
      const project = await setupMutation.mutateAsync({
        crop_cycle_id: cropCycleId,
        land_parcel_id: parcelId,
        allocated_acres: acres,
        project_name: projectName.trim(),
        field_block_name: fieldBlockName.trim() || null,
        agreement_id: agreementId || null,
        agreement_allocation_id: agreementAllocationId || null,
        project_id: projectId || null,
      });
      toast.success('Setup saved.', { id: 'setup-save' });
      navigate(`/app/projects/${project.id}`);
    } catch (e: any) {
      const friendly = humanizeSetupError(e);
      setSubmitError(friendly.message);
      if (friendly.fieldErrors) setFieldErrors(friendly.fieldErrors);
      toast.error(friendly.message, { id: 'setup-save' });
    }
  };

  const loading =
    cyclesLoading ||
    parcelsLoading ||
    (projectId ? !existingProject && setupMutation.isPending === false : false);

  const contextError = projectLoadError || allocationLoadError;

  if (loading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (contextError) {
    return (
      <div className="space-y-4 max-w-3xl">
        <PageHeader
          title="Setup context not found"
          description="The item you’re trying to set up could not be loaded (it may be invalid, deleted, or not accessible)."
          backTo="/app/projects"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: term('fieldCycles'), to: '/app/projects' },
            { label: 'Setup' },
          ]}
        />
        <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          We couldn’t load the requested setup context. Please start from the list page and try again.
        </div>
        <div className="flex gap-3">
          <button
            type="button"
            onClick={() => navigate('/app/projects')}
            className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a]"
          >
            Back to {term('fieldCycles')}
          </button>
          <button
            type="button"
            onClick={() => navigate('/app/projects/setup')}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
          >
            Add field cycle
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl">
      <PageHeader
        title={projectId ? `Complete setup` : `Add field cycle`}
        description={
          projectId
            ? `Finish linking land allocation, optional block, and agreement context for this ${term('fieldCycle').toLowerCase()}.`
            : `Create a ${term('fieldCycle').toLowerCase()} by selecting crop cycle, parcel, allocation area, and optional agreement links.`
        }
        backTo="/app/projects"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: term('fieldCycles'), to: '/app/projects' },
          { label: projectId ? 'Complete setup' : 'New setup' },
        ]}
      />

      {submitError ? (
        <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          {submitError}
        </div>
      ) : null}

      {lockedContext ? (
        <div className="rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
          <p className="font-medium text-gray-900">Context locked</p>
          <p className="mt-1">
            This setup was opened from an existing record, so crop cycle, parcel, and allocated area are locked to avoid
            creating an ambiguous setup. If you need a different crop cycle or parcel, use <span className="font-medium">Add field cycle</span> instead.
          </p>
        </div>
      ) : null}

      <div className="bg-white rounded-lg shadow p-6 space-y-2 border border-gray-100">
        <h2 className="text-base font-semibold text-gray-900">Season</h2>
        <FormField label="Crop cycle" required error={fieldErrors.crop_cycle_id}>
          <select
            value={cropCycleId}
            onChange={(e) => setCropCycleId(e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            disabled={lockedContext}
          >
            <option value="">Select crop cycle</option>
            {(cycles ?? []).map((c: CropCycle) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>
      </div>

      <div className="bg-white rounded-lg shadow p-6 space-y-2 border border-gray-100">
        <h2 className="text-base font-semibold text-gray-900">Land</h2>
        <FormField label="Parcel" required error={fieldErrors.land_parcel_id}>
          <select
            value={parcelId}
            onChange={(e) => {
              setParcelId(e.target.value);
              setAgreementAllocationId('');
            }}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            disabled={lockedContext}
          >
            <option value="">Select parcel</option>
            {(parcels ?? []).map((p: LandParcel) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </FormField>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField
            label="Allocated area (acres)"
            required
            error={fieldErrors.allocated_acres || overAllocateWarning || undefined}
          >
            <input
              type="number"
              min={0.01}
              step="0.01"
              value={allocatedAcres}
              onChange={(e) => setAllocatedAcres(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              disabled={lockedContext}
            />
          </FormField>
          <FormField label="Field block name (optional)">
            <input
              type="text"
              value={fieldBlockName}
              onChange={(e) => setFieldBlockName(e.target.value)}
              placeholder="e.g. North block"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
        </div>

        {cropCycleId && parcelId ? (
          <div className="mt-1 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <div>
                <span className="text-xs text-gray-500">Total parcel area</span>
                <div className="font-medium text-gray-900">
                  {totalParcelAcres == null ? '—' : `${totalParcelAcres.toFixed(2)} acres`}
                </div>
              </div>
              <div>
                <span className="text-xs text-gray-500">Already allocated (this crop cycle)</span>
                <div className="font-medium text-gray-900">
                  {alreadyAllocatedAcres == null ? '—' : `${alreadyAllocatedAcres.toFixed(2)} acres`}
                </div>
              </div>
              <div>
                <span className="text-xs text-gray-500">Remaining allocable</span>
                <div className="font-medium text-gray-900">
                  {remainingAllocableAcres == null ? '—' : `${remainingAllocableAcres.toFixed(2)} acres`}
                </div>
              </div>
            </div>
            {remainingAllocableAcres == null ? (
              <p className="mt-2 text-xs text-gray-500">
                Remaining area may be unavailable until allocations for this crop cycle are loaded.
              </p>
            ) : null}
          </div>
        ) : (
          <p className="text-xs text-gray-500 pt-1">
            Select a crop cycle and parcel to see how much area is left to allocate.
          </p>
        )}
        {lockedContext ? (
          <p className="text-xs text-gray-500 pt-1">
            Season/parcel/allocation context is locked to avoid ambiguous changes. Use a new setup if you need different context.
          </p>
        ) : null}
      </div>

      <div className="bg-white rounded-lg shadow p-6 space-y-2 border border-gray-100">
        <h2 className="text-base font-semibold text-gray-900">Agreement (optional)</h2>
        <FormField label="Agreement (LAND_LEASE)" error={fieldErrors.agreement_id}>
          <select
            value={agreementId}
            onChange={(e) => {
              setAgreementId(e.target.value);
              setAgreementAllocationId('');
            }}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            disabled={agreementsLoading}
          >
            <option value="">No agreement</option>
            {(agreements ?? []).map((a) => (
              <option key={a.id} value={a.id}>
                {a.party?.name ? `${a.party.name} — ` : ''}
                {a.effective_from}
                {a.effective_to ? ` → ${a.effective_to}` : ' → (open)'}
              </option>
            ))}
          </select>
        </FormField>
        {!agreementsLoading && (agreements ?? []).length === 0 ? (
          <div className="mt-1 text-sm text-gray-600">
            No eligible land lease agreements are available right now.
            <Link to="/app/crop-ops/agreements/new" className="ml-2 text-[#1F6F5C] font-medium hover:underline">
              Create agreement →
            </Link>
          </div>
        ) : null}

        <FormField label="Agreement allocation (optional)" error={fieldErrors.agreement_allocation_id}>
          <select
            value={agreementAllocationId}
            onChange={(e) => setAgreementAllocationId(e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            disabled={!agreementId || allocsLoading}
          >
            <option value="">{agreementId ? 'No agreement allocation' : 'Select agreement first'}</option>
            {(agreementAllocations ?? []).map((aa) => (
              <option key={aa.id} value={aa.id}>
                {(aa.land_parcel?.name ?? 'Parcel')}: {aa.allocated_area} {aa.area_uom ?? 'ACRE'} ({aa.status})
              </option>
            ))}
          </select>
          {agreementId && parcelId && (agreementAllocations ?? []).length === 0 && !allocsLoading ? (
            <p className="mt-1 text-xs text-gray-500">No agreement allocations found for this parcel + agreement.</p>
          ) : null}
        </FormField>
      </div>

      <div className="bg-white rounded-lg shadow p-6 space-y-2 border border-gray-100">
        <h2 className="text-base font-semibold text-gray-900">{term('fieldCycle')}</h2>
        <FormField label={`${term('fieldCycle')} name`} required error={fieldErrors.project_name}>
          <input
            type="text"
            value={projectName}
            onChange={(e) => setProjectName(e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          />
        </FormField>

        <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-2">
          <button
            type="button"
            onClick={() => navigate('/app/projects')}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            disabled={submitting}
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={!canSubmit || submitting}
            className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {submitting ? 'Saving…' : completionMode ? 'Save setup' : 'Create field cycle'}
          </button>
        </div>
      </div>
    </div>
  );
}

