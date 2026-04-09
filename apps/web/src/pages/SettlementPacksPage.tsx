import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import type { SettlementPackListItem } from '@farm-erp/shared';
import { settlementPackApi } from '../api/settlementPack';
import { PageHeader } from '../components/PageHeader';
import { FormField } from '../components/FormField';
import { useProjects } from '../hooks/useProjects';
import { useFormatting } from '../hooks/useFormatting';
import toast from 'react-hot-toast';

const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: '', label: 'All statuses' },
  { value: 'DRAFT', label: 'Draft' },
  { value: 'FINALIZED', label: 'Finalized' },
  { value: 'VOID', label: 'Void' },
];

export default function SettlementPacksPage() {
  const navigate = useNavigate();
  const { formatDate } = useFormatting();
  const { data: projects } = useProjects();
  const [rows, setRows] = useState<SettlementPackListItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [projectId, setProjectId] = useState('');
  const [referenceNo, setReferenceNo] = useState('');
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      try {
        setLoading(true);
        setError(null);
        const res = await settlementPackApi.list(
          statusFilter ? { status: statusFilter } : undefined
        );
        if (!cancelled) {
          setRows(res.data);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to load settlement packs');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };
    load();
    return () => {
      cancelled = true;
    };
  }, [statusFilter]);

  const handleCreate = async () => {
    if (!projectId) {
      toast.error('Select a project');
      return;
    }
    setCreating(true);
    try {
      const body: { project_id: string; reference_no?: string } = { project_id: projectId };
      const ref = referenceNo.trim();
      if (ref) {
        body.reference_no = ref;
      }
      const created = await settlementPackApi.create(body);
      toast.success('Settlement pack ready');
      navigate(`/app/settlement-packs/${created.id}`);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to create settlement pack');
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Settlement packs"
        backTo="/app/governance"
        breadcrumbs={[
          { label: 'Governance', to: '/app/governance' },
          { label: 'Settlement packs' },
        ]}
      />

      <section className="bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-semibold mb-4">New settlement pack</h2>
        <p className="text-sm text-gray-600 mb-4">
          Creates or opens the pack for this project and reference (default reference if left empty).
        </p>
        <div className="flex flex-col sm:flex-row gap-4 sm:items-end">
          <FormField label="Project">
            <select
              id="sp-project"
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              value={projectId}
              onChange={(e) => setProjectId(e.target.value)}
            >
              <option value="">Select project…</option>
              {(projects ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Reference (optional)">
            <input
              id="sp-ref"
              type="text"
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              value={referenceNo}
              onChange={(e) => setReferenceNo(e.target.value)}
              placeholder="default"
              maxLength={64}
            />
          </FormField>
          <button
            type="button"
            onClick={handleCreate}
            disabled={creating || !projectId}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50 text-sm font-medium whitespace-nowrap"
          >
            {creating ? 'Opening…' : 'Open or create'}
          </button>
        </div>
      </section>

      <section className="bg-white rounded-lg shadow overflow-hidden">
        <div className="p-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <h2 className="text-lg font-semibold">All packs</h2>
          <label className="flex items-center gap-2 text-sm">
            <span className="text-gray-600">Status</span>
            <select
              className="border border-gray-300 rounded-md px-2 py-1.5 text-sm"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
            >
              {STATUS_OPTIONS.map((o) => (
                <option key={o.value || 'all'} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </label>
        </div>

        {loading ? (
          <div className="p-8 text-center text-gray-500">Loading…</div>
        ) : error ? (
          <div className="p-6 text-red-700">{error}</div>
        ) : rows.length === 0 ? (
          <div className="p-8 text-center text-gray-500">No settlement packs yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Project
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Reference
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Status
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    As of
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Prepared
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Finalized
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">
                      {row.project?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-sm font-mono text-gray-700">{row.reference_no}</td>
                    <td className="px-4 py-2 text-sm">{row.status}</td>
                    <td className="px-4 py-2 text-sm tabular-nums">
                      {row.as_of_date ? formatDate(row.as_of_date) : '—'}
                    </td>
                    <td className="px-4 py-2 text-sm tabular-nums">
                      {row.prepared_at ? formatDate(row.prepared_at) : '—'}
                    </td>
                    <td className="px-4 py-2 text-sm tabular-nums">
                      {row.finalized_at ? formatDate(row.finalized_at) : '—'}
                    </td>
                    <td className="px-4 py-2 text-right">
                      <Link
                        to={`/app/settlement-packs/${row.id}`}
                        className="text-[#1F6F5C] hover:underline text-sm font-medium"
                      >
                        View
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
