import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../../components/PageContainer';
import { PageHeader } from '../../components/PageHeader';
import { FormField } from '../../components/FormField';
import type { CostCenter } from '../../types';
import toast from 'react-hot-toast';

export default function CostCentersPage() {
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [description, setDescription] = useState('');

  const { data: rows = [], isLoading, error } = useQuery({
    queryKey: ['cost-centers'],
    queryFn: () => apiClient.get<CostCenter[]>('/api/cost-centers'),
  });

  const createM = useMutation({
    mutationFn: () =>
      apiClient.post<CostCenter>('/api/cost-centers', {
        name: name.trim(),
        code: code.trim() || undefined,
        description: description.trim() || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['cost-centers'] });
      setName('');
      setCode('');
      setDescription('');
      toast.success('Cost center created');
    },
    onError: (e: Error) => toast.error(e.message || 'Could not create'),
  });

  const deactivateM = useMutation({
    mutationFn: (id: string) =>
      apiClient.put<CostCenter>(`/api/cost-centers/${id}`, { status: 'INACTIVE' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['cost-centers'] });
      toast.success('Updated');
    },
    onError: (e: Error) => toast.error(e.message || 'Could not update'),
  });

  const reactivateM = useMutation({
    mutationFn: (id: string) =>
      apiClient.put<CostCenter>(`/api/cost-centers/${id}`, { status: 'ACTIVE' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['cost-centers'] });
      toast.success('Updated');
    },
    onError: (e: Error) => toast.error(e.message || 'Could not update'),
  });

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title="Cost centers"
        backTo="/app/accounting/bills"
        breadcrumbs={[{ label: 'Bills', to: '/app/accounting/bills' }, { label: 'Cost centers' }]}
      />
      <p className="text-sm text-gray-600 -mt-2">
        Non-project scopes for farm overhead (for example Admin, HQ, Processing). Posted bills attach to one cost
        center so reporting can separate project profit from farm-wide costs.
      </p>

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold">Add cost center</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormField label="Name">
            <input
              id="cc-name"
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Farm HQ"
            />
          </FormField>
          <FormField label="Code (optional)">
            <input
              id="cc-code"
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="ADMIN"
            />
          </FormField>
        </div>
        <FormField label="Description (optional)">
          <textarea
            id="cc-desc"
            className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
            rows={2}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
          />
        </FormField>
        <button
          type="button"
          disabled={!name.trim() || createM.isPending}
          onClick={() => createM.mutate()}
          className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#185647] disabled:opacity-50"
        >
          {createM.isPending ? 'Saving…' : 'Create'}
        </button>
      </section>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        {isLoading && <div className="p-8 text-center text-gray-500">Loading…</div>}
        {error && <div className="p-6 text-red-700">{error instanceof Error ? error.message : 'Failed to load'}</div>}
        {!isLoading && !error && rows.length === 0 && (
          <div className="p-8 text-center text-gray-500">No cost centers yet.</div>
        )}
        {!isLoading && !error && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900">{r.name}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{r.code ?? '—'}</td>
                    <td className="px-4 py-3 text-sm">{r.status}</td>
                    <td className="px-4 py-3 text-right text-sm">
                      {r.status === 'ACTIVE' ? (
                        <button
                          type="button"
                          className="text-amber-800 hover:underline"
                          disabled={deactivateM.isPending}
                          onClick={() => deactivateM.mutate(r.id)}
                        >
                          Set inactive
                        </button>
                      ) : (
                        <button
                          type="button"
                          className="text-[#1F6F5C] hover:underline"
                          disabled={reactivateM.isPending}
                          onClick={() => reactivateM.mutate(r.id)}
                        >
                          Reactivate
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </PageContainer>
  );
}
