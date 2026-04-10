import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateFieldJob } from '../../../hooks/useFieldJobs';
import { useProjects } from '../../../hooks/useProjects';
import { useProductionUnits } from '../../../hooks/useProductionUnits';
import { useLandParcels } from '../../../hooks/useLandParcels';
import { useActivityTypes } from '../../../hooks/useCropOps';
import { FormField } from '../../../components/FormField';
import { PageHeader } from '../../../components/PageHeader';
import { PrimaryWorkflowBanner } from '../../../components/workflow/PrimaryWorkflowBanner';

export default function FieldJobFormPage() {
  const navigate = useNavigate();
  const createM = useCreateFieldJob();
  const { data: projects } = useProjects();
  const { data: productionUnits } = useProductionUnits();
  const { data: landParcels } = useLandParcels();
  const { data: activityTypes } = useActivityTypes({ is_active: true });

  const [doc_no, setDocNo] = useState('');
  const [job_date, setJobDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [project_id, setProjectId] = useState('');
  const [production_unit_id, setProductionUnitId] = useState('');
  const [land_parcel_id, setLandParcelId] = useState('');
  const [crop_activity_type_id, setCropActivityTypeId] = useState('');
  const [notes, setNotes] = useState('');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!project_id) return;
    const job = await createM.mutateAsync({
      doc_no: doc_no.trim() || undefined,
      job_date,
      project_id,
      production_unit_id: production_unit_id || undefined,
      land_parcel_id: land_parcel_id || undefined,
      crop_activity_type_id: crop_activity_type_id || undefined,
      notes: notes.trim() || undefined,
    });
    navigate(`/app/crop-ops/field-jobs/${job.id}`);
  };

  return (
    <div className="space-y-6 max-w-3xl">
      <PageHeader
        title="New field job"
        description="Create a draft job, then add inputs, labour, and machinery on the next screen before posting."
        backTo="/app/crop-ops/field-jobs"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops Overview', to: '/app/crop-ops' },
          { label: 'Field jobs', to: '/app/crop-ops/field-jobs' },
          { label: 'New' },
        ]}
      />

      <PrimaryWorkflowBanner variant="field-job" />

      <form onSubmit={handleSubmit} className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
        <FormField label="Job date" required>
          <input
            id="fj-job-date"
            type="date"
            required
            value={job_date}
            onChange={(e) => setJobDate(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </FormField>

        <FormField label="Project (field cycle)" required>
          <select
            id="fj-project"
            required
            value={project_id}
            onChange={(e) => setProjectId(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">Select project…</option>
            {(projects ?? []).map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Document reference (optional)">
          <input
            id="fj-doc"
            type="text"
            value={doc_no}
            onChange={(e) => setDocNo(e.target.value)}
            placeholder="e.g. FJ-2026-001"
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            maxLength={100}
          />
        </FormField>

        <FormField label="Production unit (optional)">
          <select
            id="fj-pu"
            value={production_unit_id}
            onChange={(e) => setProductionUnitId(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">—</option>
            {(productionUnits ?? []).map((u) => (
              <option key={u.id} value={u.id}>
                {u.name || u.id}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Land parcel (optional)">
          <select
            id="fj-land"
            value={land_parcel_id}
            onChange={(e) => setLandParcelId(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">—</option>
            {(landParcels ?? []).map((lp) => (
              <option key={lp.id} value={lp.id}>
                {lp.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Work type (optional)">
          <select
            id="fj-type"
            value={crop_activity_type_id}
            onChange={(e) => setCropActivityTypeId(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">—</option>
            {(activityTypes ?? []).map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Notes">
          <textarea
            id="fj-notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={3}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            placeholder="Operational notes…"
          />
        </FormField>

        <div className="flex flex-wrap gap-3 pt-2">
          <button
            type="submit"
            disabled={createM.isPending || !project_id}
            className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {createM.isPending ? 'Creating…' : 'Create draft & continue'}
          </button>
          <button
            type="button"
            onClick={() => navigate('/app/crop-ops/field-jobs')}
            className="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>
  );
}
