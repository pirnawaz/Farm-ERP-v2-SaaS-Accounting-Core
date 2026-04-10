import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { PageHeader } from '../../../components/PageHeader';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { PrimaryWorkflowBanner } from '../../../components/workflow/PrimaryWorkflowBanner';

const BASE = '/api/v1/crop-ops';

export type CropOpsAgreement = {
  id: string;
  agreement_type: string;
  status: string;
  priority: number;
  effective_from: string;
  effective_to: string | null;
  project?: { id: string; name: string } | null;
  crop_cycle?: { id: string; name: string } | null;
  party?: { id: string; name: string } | null;
  machine?: { id: string; code: string; name: string } | null;
  worker?: { id: string; name: string } | null;
};

function typeLabel(t: string): string {
  if (t === 'MACHINE_USAGE') return 'Machine usage';
  if (t === 'LABOUR') return 'Labour';
  if (t === 'LAND_LEASE') return 'Land lease';
  return t;
}

export default function AgreementsPage() {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['crop-ops', 'agreements'],
    queryFn: () => apiClient.get<CropOpsAgreement[]>(`${BASE}/agreements`),
  });

  return (
    <div className="space-y-6">
      <PageHeader
        title="Agreements"
        description="Formal rules for harvest output shares (machine, labour, landlord). The API resolves active agreements per harvest; suggestions and “Apply agreements” on a harvest use these terms."
        backTo="/app/crop-ops"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops', to: '/app/crop-ops' },
          { label: 'Agreements' },
        ]}
      />
      <PrimaryWorkflowBanner variant="field-job" />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-gray-600 max-w-2xl">
          Create and edit agreements here. They do not post automatically — use a draft harvest to review suggestions and
          apply lines when you are ready.
        </p>
        <Link
          to="/app/crop-ops/agreements/new"
          className="inline-flex items-center px-4 py-2 bg-[#1F6F5C] text-white rounded text-sm font-medium hover:bg-[#185A4A]"
        >
          New agreement
        </Link>
      </div>

      {isLoading && (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      )}
      {isError && (
        <div className="rounded-md bg-red-50 text-red-800 text-sm px-3 py-2">
          {(error as Error)?.message || 'Could not load agreements.'}
        </div>
      )}

      {data && (
        <div className="bg-white rounded-lg shadow overflow-hidden border border-gray-100">
          <table className="min-w-full text-sm">
            <thead className="bg-[#E6ECEA] text-left text-xs text-gray-600 uppercase tracking-wide">
              <tr>
                <th className="px-4 py-3">Type</th>
                <th className="px-4 py-3">Scope</th>
                <th className="px-4 py-3">Subject</th>
                <th className="px-4 py-3">Effective</th>
                <th className="px-4 py-3">Priority</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3 w-24" />
              </tr>
            </thead>
            <tbody>
              {data.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-gray-500">
                    No agreements yet. Add one to drive harvest share suggestions.
                  </td>
                </tr>
              ) : (
                data.map((a) => {
                  const scope = [a.project?.name, a.crop_cycle?.name].filter(Boolean).join(' · ') || '—';
                  const subject =
                    a.machine?.name || a.machine?.code
                      ? `${a.machine?.name ?? a.machine?.code}`
                      : a.worker?.name
                        ? a.worker.name
                        : a.party?.name
                          ? a.party.name
                          : '—';
                  return (
                    <tr key={a.id} className="border-t border-gray-100 hover:bg-gray-50/80">
                      <td className="px-4 py-3 font-medium text-gray-900">{typeLabel(a.agreement_type)}</td>
                      <td className="px-4 py-3 text-gray-700">{scope}</td>
                      <td className="px-4 py-3 text-gray-700">{subject}</td>
                      <td className="px-4 py-3 tabular-nums text-gray-700">
                        {a.effective_from}
                        {a.effective_to ? ` → ${a.effective_to}` : ' → (open)'}
                      </td>
                      <td className="px-4 py-3 tabular-nums">{a.priority}</td>
                      <td className="px-4 py-3">
                        <span
                          className={`inline-flex rounded px-2 py-0.5 text-xs font-medium ${
                            a.status === 'ACTIVE' ? 'bg-emerald-100 text-emerald-900' : 'bg-gray-100 text-gray-700'
                          }`}
                        >
                          {a.status}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <Link to={`/app/crop-ops/agreements/${a.id}`} className="text-[#1F6F5C] hover:underline text-sm">
                          Edit
                        </Link>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
