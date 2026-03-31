import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { cropCyclesApi, type SeasonSetupAssignment } from '../../api/cropCycles';
import { useCropItems } from '../../hooks/useCropItems';
import { useLandParcels } from '../../hooks/useLandParcels';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { term } from '../../config/terminology';
import toast from 'react-hot-toast';
import type { CreateCropCyclePayload } from '../../types';

type Step = 1 | 2;

export type BlockRow = { tenant_crop_item_id: string; name: string; area: string };

const initialBlockRow = (): BlockRow => ({
  tenant_crop_item_id: '',
  name: '',
  area: '',
});

const initialCycleForm: CreateCropCyclePayload = {
  name: '',
  tenant_crop_item_id: '',
  start_date: '',
  end_date: '',
};

export default function SeasonSetupWizardPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [step, setStep] = useState<Step>(1);
  const [cycleForm, setCycleForm] = useState<CreateCropCyclePayload>(initialCycleForm);
  const [createdCycleId, setCreatedCycleId] = useState<string | null>(null);
  /** Per-parcel list of blocks. Simple mode uses only the first block. */
  const [assignments, setAssignments] = useState<Record<string, BlockRow[]>>({});
  const [advancedMode, setAdvancedMode] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const { data: cropItems, isLoading: cropItemsLoading } = useCropItems();
  const { data: landParcels, isLoading: parcelsLoading } = useLandParcels();

  const canProceedStep1 =
    cycleForm.name.trim() &&
    cycleForm.tenant_crop_item_id &&
    cycleForm.start_date;

  const handleCreateSeason = async () => {
    if (!canProceedStep1) return;
    setSubmitting(true);
    try {
      const payload: CreateCropCyclePayload = {
        name: cycleForm.name.trim(),
        tenant_crop_item_id: cycleForm.tenant_crop_item_id,
        start_date: cycleForm.start_date,
        ...(cycleForm.end_date?.trim() && { end_date: cycleForm.end_date.trim() }),
      };
      const cycle = await cropCyclesApi.create(payload);
      setCreatedCycleId(cycle.id);
      setStep(2);
      queryClient.invalidateQueries({ queryKey: ['crop-cycles'] });
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } };
      toast.error(err?.response?.data?.message ?? 'Failed to create season');
    } finally {
      setSubmitting(false);
    }
  };

  const handleAssignFields = async () => {
    if (!createdCycleId) return;
    const payload: SeasonSetupAssignment[] = [];
    for (const p of landParcels ?? []) {
      const rows = assignments[p.id] ?? [initialBlockRow()];
      const blocks = rows
        .filter((b) => b.tenant_crop_item_id)
        .map((b) => ({
          tenant_crop_item_id: b.tenant_crop_item_id,
          ...(b.name?.trim() && { name: b.name.trim() }),
          ...(b.area && !Number.isNaN(Number(b.area)) && Number(b.area) > 0 && { area: Number(b.area) }),
        }));
      if (blocks.length > 0) payload.push({ land_parcel_id: p.id, blocks });
    }
    if (payload.length === 0) {
      toast.error('Select at least one field and choose a crop for each block.');
      return;
    }
    setSubmitting(true);
    try {
      const res = await cropCyclesApi.seasonSetup(createdCycleId, { assignments: payload });
      queryClient.invalidateQueries({ queryKey: ['crop-cycles'] });
      queryClient.invalidateQueries({ queryKey: ['land-allocations'] });
      const totalBlocks = res.projects?.length ?? res.projects_created ?? 0;
      if (totalBlocks > payload.length) {
        toast.success(`Created ${totalBlocks} field blocks across ${payload.length} field(s).`);
      } else {
        toast.success(`${payload.length} field(s) added to season.`);
      }
      navigate(`/app/crop-cycles/${createdCycleId}`);
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } };
      toast.error(err?.response?.data?.message ?? 'Failed to assign fields');
    } finally {
      setSubmitting(false);
    }
  };

  const skipAssignments = () => {
    if (createdCycleId) navigate(`/app/crop-cycles/${createdCycleId}`);
  };

  return (
    <div className="max-w-5xl mx-auto pb-8 space-y-6">
      <PageHeader
        title={term('seasonSetup')}
        backTo="/app/crop-cycles"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Cycles', to: '/app/crop-cycles' },
          { label: term('seasonSetup') },
        ]}
      />

      <div className="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        {/* Step indicator */}
        <div className="flex border-b border-gray-200">
          <button
            type="button"
            onClick={() => step === 2 && setStep(1)}
            className={`flex-1 px-4 py-3 text-sm font-medium ${
              step === 1 ? 'bg-[#1F6F5C] text-white' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'
            }`}
          >
            1. Create season
          </button>
          <button
            type="button"
            onClick={() => createdCycleId && setStep(2)}
            className={`flex-1 px-4 py-3 text-sm font-medium ${
              step === 2 ? 'bg-[#1F6F5C] text-white' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'
            }`}
          >
            2. {term('assignFieldsToSeason')}
          </button>
        </div>

        <div className="p-6">
          {step === 1 && (
            <>
              <h2 className="text-lg font-semibold text-gray-900 mb-4">Create season</h2>
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Season name</label>
                  <input
                    type="text"
                    value={cycleForm.name}
                    onChange={(e) => setCycleForm((f) => ({ ...f, name: e.target.value }))}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                    placeholder="e.g. Season 2025"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Primary crop (for this season)</label>
                  <select
                    value={cycleForm.tenant_crop_item_id}
                    onChange={(e) => setCycleForm((f) => ({ ...f, tenant_crop_item_id: e.target.value }))}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                  >
                    <option value="">Select crop</option>
                    {(cropItems ?? []).map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.display_name ?? c.custom_name ?? c.catalog_code ?? c.id}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Start date</label>
                    <input
                      type="date"
                      value={cycleForm.start_date}
                      onChange={(e) => setCycleForm((f) => ({ ...f, start_date: e.target.value }))}
                      className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">End date (optional)</label>
                    <input
                      type="date"
                      value={cycleForm.end_date ?? ''}
                      onChange={(e) => setCycleForm((f) => ({ ...f, end_date: e.target.value || undefined }))}
                      className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                    />
                  </div>
                </div>
              </div>
              <div className="mt-6 flex gap-3">
                <button
                  type="button"
                  onClick={handleCreateSeason}
                  disabled={!canProceedStep1 || submitting || cropItemsLoading}
                  className="px-4 py-2 bg-[#1F6F5C] text-white rounded-lg font-medium hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {submitting ? 'Creating…' : 'Create season & assign fields'}
                </button>
                <Link
                  to="/app/crop-cycles"
                  className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
                >
                  Cancel
                </Link>
              </div>
            </>
          )}

          {step === 2 && (
            <>
              <h2 className="text-lg font-semibold text-gray-900 mb-2">{term('assignFieldsToSeason')}</h2>
              <p className="text-sm text-gray-500 mb-2">
                Select fields and the crop for each. One costing container (field block) per block will be created.
              </p>
              <label className="flex items-center gap-2 mb-4">
                <input
                  type="checkbox"
                  checked={advancedMode}
                  onChange={(e) => setAdvancedMode(e.target.checked)}
                  className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
                />
                <span className="text-sm font-medium text-gray-700">{term('advancedSetup')}</span>
              </label>
              {parcelsLoading ? (
                <div className="flex justify-center py-8">
                  <LoadingSpinner />
                </div>
              ) : !landParcels?.length ? (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                  No land parcels yet. Add fields (land parcels) first from Land Parcels, then return here.
                  <Link to="/app/land" className="ml-2 text-[#1F6F5C] font-medium hover:underline">
                    Go to Land Parcels →
                  </Link>
                </div>
              ) : (
                <div className="space-y-4">
                  {(landParcels ?? []).map((parcel) => {
                    const rows = assignments[parcel.id]?.length ? assignments[parcel.id] : [initialBlockRow()];
                    const parcelArea = parcel.total_acres != null ? Number(parcel.total_acres) : null;
                    const totalBlockArea = rows.reduce(
                      (sum, b) => sum + (b.area && !Number.isNaN(Number(b.area)) ? Number(b.area) : 0),
                      0
                    );
                    const areaExceeded = parcelArea != null && totalBlockArea > parcelArea;
                    return (
                      <div
                        key={parcel.id}
                        className="rounded-lg border border-gray-200 bg-gray-50 overflow-hidden"
                      >
                        <div className="flex flex-wrap items-center gap-3 p-3 border-b border-gray-200">
                          <span className="font-medium text-gray-900">{parcel.name}</span>
                          <span className="text-sm text-gray-500">
                            {parcel.total_acres != null ? `(${parcel.total_acres} ac)` : ''}
                          </span>
                          {advancedMode && (
                            <button
                              type="button"
                              onClick={() =>
                                setAssignments((prev) => ({
                                  ...prev,
                                  [parcel.id]: [...(prev[parcel.id] ?? [initialBlockRow()]), initialBlockRow()],
                                }))
                              }
                              className="text-sm text-[#1F6F5C] hover:underline font-medium"
                            >
                              + Add {term('fieldBlocks').toLowerCase().replace(/s$/, '')}
                            </button>
                          )}
                        </div>
                        <div className="p-3 space-y-2">
                          {(advancedMode ? rows : [rows[0] ?? initialBlockRow()]).map((block, blockIdx) => (
                            <div
                              key={blockIdx}
                              className="flex flex-wrap items-center gap-2"
                            >
                              <select
                                value={block.tenant_crop_item_id}
                                onChange={(e) =>
                                  setAssignments((prev) => {
                                    const list = [...(prev[parcel.id] ?? [initialBlockRow()])];
                                    if (!advancedMode) list[0] = { ...list[0], tenant_crop_item_id: e.target.value };
                                    else list[blockIdx] = { ...list[blockIdx], tenant_crop_item_id: e.target.value };
                                    return { ...prev, [parcel.id]: list };
                                  })
                                }
                                className="rounded border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-[#1F6F5C] min-w-[140px]"
                              >
                                <option value="">No crop</option>
                                {(cropItems ?? []).map((c) => (
                                  <option key={c.id} value={c.id}>
                                    {c.display_name ?? c.custom_name ?? c.catalog_code ?? c.id}
                                  </option>
                                ))}
                              </select>
                              {advancedMode && (
                                <input
                                  type="text"
                                  placeholder="Block name (optional)"
                                  value={block.name}
                                  onChange={(e) =>
                                    setAssignments((prev) => {
                                      const list = [...(prev[parcel.id] ?? [initialBlockRow()])];
                                      list[blockIdx] = { ...list[blockIdx], name: e.target.value };
                                      return { ...prev, [parcel.id]: list };
                                    })
                                  }
                                  className="w-32 rounded border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-[#1F6F5C]"
                                />
                              )}
                              <input
                                type="number"
                                min="0.01"
                                step="0.01"
                                placeholder="Area (optional)"
                                value={block.area}
                                onChange={(e) =>
                                  setAssignments((prev) => {
                                    const list = [...(prev[parcel.id] ?? [initialBlockRow()])];
                                    const idx = advancedMode ? blockIdx : 0;
                                    list[idx] = { ...list[idx], area: e.target.value };
                                    return { ...prev, [parcel.id]: list };
                                  })
                                }
                                className="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm focus:ring-2 focus:ring-[#1F6F5C]"
                              />
                              {advancedMode && rows.length > 1 && (
                                <button
                                  type="button"
                                  onClick={() =>
                                    setAssignments((prev) => {
                                      const list = (prev[parcel.id] ?? [initialBlockRow()]).filter(
                                        (_, i) => i !== blockIdx
                                      );
                                      return { ...prev, [parcel.id]: list.length ? list : [initialBlockRow()] };
                                    })
                                  }
                                  className="text-red-600 hover:text-red-800 text-sm"
                                  aria-label="Remove block"
                                >
                                  Remove
                                </button>
                              )}
                            </div>
                          ))}
                          {areaExceeded && (
                            <p className="text-sm text-amber-700">
                              Total block area ({totalBlockArea}) exceeds parcel area ({parcelArea}).
                            </p>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
              <div className="mt-6 flex gap-3">
                <button
                  type="button"
                  onClick={handleAssignFields}
                  disabled={
                    submitting ||
                    !(landParcels ?? []).some((p) => {
                      const rows = assignments[p.id] ?? [initialBlockRow()];
                      return rows.some((b) => b.tenant_crop_item_id);
                    })
                  }
                  className="px-4 py-2 bg-[#1F6F5C] text-white rounded-lg font-medium hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {submitting ? 'Saving…' : 'Assign selected fields'}
                </button>
                <button
                  type="button"
                  onClick={skipAssignments}
                  className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
                >
                  Skip (add fields later)
                </button>
                <Link
                  to={`/app/crop-cycles/${createdCycleId}`}
                  className="px-4 py-2 text-gray-600 hover:text-gray-900"
                >
                  View season →
                </Link>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
