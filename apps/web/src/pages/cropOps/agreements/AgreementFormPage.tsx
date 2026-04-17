import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { apiClient } from '@farm-erp/shared';
import { PageHeader } from '../../../components/PageHeader';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { FormField } from '../../../components/FormField';
import { useCropCycles } from '../../../hooks/useCropCycles';
import { useProjects } from '../../../hooks/useProjects';
import { useParties } from '../../../hooks/useParties';
import { useMachinesQuery } from '../../../hooks/useMachinery';
import { useWorkers } from '../../../hooks/useLabour';
import type { CropOpsAgreement } from './AgreementsPage';

const BASE = '/api/v1/crop-ops';

const AGREEMENT_TYPES = [
  { value: 'MACHINE_USAGE', label: 'Machine usage' },
  { value: 'LABOUR', label: 'Labour' },
  { value: 'LAND_LEASE', label: 'Land lease (landlord)' },
] as const;

type AgreementAllocationRow = {
  id: string;
  allocated_area: string;
  land_parcel?: { name: string };
  status: string;
  starts_on: string;
};

function errMessage(e: unknown): string {
  return (
    (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data?.message ||
    (e as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors?.agreement_type?.[0] ||
    'Request failed'
  );
}

export default function AgreementFormPage() {
  const { id } = useParams<{ id: string }>();
  const isEdit = Boolean(id && id !== 'new');
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['crop-ops', 'agreements', id],
    queryFn: () => apiClient.get<CropOpsAgreement & { terms?: Record<string, unknown> | null }>(`${BASE}/agreements/${id}`),
    enabled: isEdit,
  });

  const { data: cropCycles } = useCropCycles();
  const [cropCycleId, setCropCycleId] = useState('');
  const { data: projects } = useProjects(cropCycleId || undefined);
  const { data: parties } = useParties();
  const { data: machinesData } = useMachinesQuery();
  const { data: workersData } = useWorkers();

  const machines = machinesData ?? [];
  const workers = workersData ?? [];

  const [agreement_type, setAgreementType] = useState<string>('MACHINE_USAGE');
  const [project_id, setProjectId] = useState('');
  const [party_id, setPartyId] = useState('');
  const [machine_id, setMachineId] = useState('');
  const [worker_id, setWorkerId] = useState('');
  const [effective_from, setEffectiveFrom] = useState('');
  const [effective_to, setEffectiveTo] = useState('');
  const [priority, setPriority] = useState('0');
  const [status, setStatus] = useState('ACTIVE');
  const [termsJson, setTermsJson] = useState('{\n  "basis": "PERCENT",\n  "percent": 10\n}');

  useEffect(() => {
    if (existing) {
      setAgreementType(existing.agreement_type);
      setCropCycleId(existing.crop_cycle?.id ?? '');
      setProjectId(existing.project?.id ?? '');
      setPartyId(existing.party?.id ?? '');
      setMachineId(existing.machine?.id ?? '');
      setWorkerId(existing.worker?.id ?? '');
      setEffectiveFrom(String(existing.effective_from).slice(0, 10));
      setEffectiveTo(existing.effective_to ? String(existing.effective_to).slice(0, 10) : '');
      setPriority(String(existing.priority ?? 0));
      setStatus(existing.status ?? 'ACTIVE');
      const t = (existing as { terms?: unknown }).terms;
      setTermsJson(t ? JSON.stringify(t, null, 2) : '{\n  "basis": "PERCENT",\n  "percent": 10\n}');
    }
  }, [existing]);

  const saveM = useMutation({
    mutationFn: async () => {
      let terms: Record<string, unknown> | null = null;
      const trimmed = termsJson.trim();
      if (trimmed) {
        try {
          terms = JSON.parse(trimmed) as Record<string, unknown>;
        } catch {
          throw new Error('Terms must be valid JSON.');
        }
      }
      const payload = {
        agreement_type,
        project_id: project_id || null,
        crop_cycle_id: cropCycleId || null,
        party_id: party_id || null,
        machine_id: machine_id || null,
        worker_id: worker_id || null,
        terms,
        effective_from,
        effective_to: effective_to || null,
        priority: parseInt(priority, 10) || 0,
        status,
      };
      if (isEdit && id) {
        return apiClient.put<CropOpsAgreement>(`${BASE}/agreements/${id}`, payload);
      }
      return apiClient.post<CropOpsAgreement>(`${BASE}/agreements`, payload);
    },
    onSuccess: (row) => {
      void qc.invalidateQueries({ queryKey: ['crop-ops', 'agreements'] });
      toast.success(isEdit ? 'Agreement updated' : 'Agreement created');
      navigate(`/app/crop-ops/agreements/${row.id}`);
    },
    onError: (e: unknown) => toast.error(errMessage(e)),
  });

  if (isEdit && loadingExisting) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  const landAgreementAllocations =
    existing && agreement_type === 'LAND_LEASE'
      ? (existing as unknown as { agreement_allocations?: AgreementAllocationRow[] }).agreement_allocations
      : undefined;

  return (
    <div className="space-y-6 max-w-3xl">
      <PageHeader
        title={isEdit ? 'Edit agreement' : 'New agreement'}
        description="Parties and commercial terms live on the agreement. For field-cycle projects, define distribution and deductions under JSON — use a settlement block (profit splits, optional deductions) when this agreement drives project settlement."
        backTo="/app/crop-ops/agreements"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops', to: '/app/crop-ops' },
          { label: 'Agreements', to: '/app/crop-ops/agreements' },
          { label: isEdit ? 'Edit' : 'New' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4 border border-gray-100">
        <p className="text-sm text-gray-600">
          Required links depend on type: machine usage needs a machine; labour needs a worker; land lease needs a party
          (landlord). Optional project and crop cycle narrow the scope.
        </p>

        <FormField label="Type">
          <select
            value={agreement_type}
            onChange={(e) => setAgreementType(e.target.value)}
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            {AGREEMENT_TYPES.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Crop cycle (optional)">
          <select
            value={cropCycleId}
            onChange={(e) => {
              setCropCycleId(e.target.value);
              setProjectId('');
            }}
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="">Any / tenant-wide</option>
            {(cropCycles ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Field cycle / project (optional)">
          <select
            value={project_id}
            onChange={(e) => setProjectId(e.target.value)}
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            disabled={!cropCycleId}
          >
            <option value="">{cropCycleId ? 'Any in cycle' : 'Select crop cycle first'}</option>
            {(projects ?? []).map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </FormField>

        {agreement_type === 'MACHINE_USAGE' && (
          <FormField label="Machine">
            <select
              value={machine_id}
              onChange={(e) => setMachineId(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            >
              <option value="">Select…</option>
              {machines.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.code ? `${m.code} — ${m.name}` : m.name}
                </option>
              ))}
            </select>
          </FormField>
        )}

        {agreement_type === 'LABOUR' && (
          <FormField label="Worker">
            <select
              value={worker_id}
              onChange={(e) => setWorkerId(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            >
              <option value="">Select…</option>
              {workers.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
          </FormField>
        )}

        {agreement_type === 'LAND_LEASE' && (
          <FormField label="Party (landlord)">
            <select
              value={party_id}
              onChange={(e) => setPartyId(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            >
              <option value="">Select…</option>
              {(parties ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
        )}

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Effective from">
            <input
              type="date"
              value={effective_from}
              onChange={(e) => setEffectiveFrom(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            />
          </FormField>
          <FormField label="Effective to (optional)">
            <input
              type="date"
              value={effective_to}
              onChange={(e) => setEffectiveTo(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            />
          </FormField>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Priority (higher wins)">
            <input
              type="number"
              value={priority}
              onChange={(e) => setPriority(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
              min={0}
            />
          </FormField>
          <FormField label="Status">
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            >
              <option value="ACTIVE">ACTIVE</option>
              <option value="INACTIVE">INACTIVE</option>
            </select>
          </FormField>
        </div>

        <FormField label="Terms (JSON) — include settlement for project-linked land agreements">
          <p className="text-xs text-gray-500 mb-1">
            Active land agreements scoped to a field-cycle project must include a parseable{' '}
            <code className="bg-gray-100 px-1 rounded">settlement</code> object:{' '}
            <code className="bg-gray-100 px-1 rounded">profit_split_landlord_pct</code>,{' '}
            <code className="bg-gray-100 px-1 rounded">profit_split_hari_pct</code> (sum 100), optional{' '}
            <code className="bg-gray-100 px-1 rounded">kamdari_pct</code> and{' '}
            <code className="bg-gray-100 px-1 rounded">kamdar_party_id</code>. Harvest share lines may use other keys in
            the same JSON.
          </p>
          <textarea
            value={termsJson}
            onChange={(e) => setTermsJson(e.target.value)}
            rows={10}
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm font-mono"
          />
        </FormField>

        {isEdit &&
          id &&
          agreement_type === 'LAND_LEASE' &&
          existing &&
          Array.isArray(landAgreementAllocations) &&
          landAgreementAllocations.length > 0 && (
            <div className="rounded border border-gray-200 bg-[#F7FAF9] p-4">
              <h3 className="text-sm font-medium text-gray-900 mb-2">Parcel allocations</h3>
              <ul className="text-sm text-gray-700 space-y-1">
                {landAgreementAllocations.map((a) => (
                  <li key={a.id}>
                    {a.land_parcel?.name ?? 'Parcel'}: {a.allocated_area} acres — {a.status} (from {a.starts_on})
                  </li>
                ))}
              </ul>
            </div>
          )}

        <div className="flex flex-wrap gap-2 pt-2">
          <button
            type="button"
            disabled={saveM.isPending || !effective_from}
            onClick={() => saveM.mutate()}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded text-sm disabled:opacity-50"
          >
            {saveM.isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create agreement'}
          </button>
          <button
            type="button"
            onClick={() => navigate('/app/crop-ops/agreements')}
            className="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
}
