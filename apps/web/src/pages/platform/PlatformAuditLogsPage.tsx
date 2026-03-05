import { useState, useCallback } from 'react';
import { useQuery } from '@tanstack/react-query';
import { platformApi, type PlatformAuditLogItem, type PlatformAuditLogsParams } from '../../api/platform';
import { usePlatformTenants } from '../../hooks/usePlatformTenants';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';

export default function PlatformAuditLogsPage() {
  const { formatDate } = useFormatting();
  const [filters, setFilters] = useState<PlatformAuditLogsParams>({
    page: 1,
    per_page: 15,
  });
  const [tenantId, setTenantId] = useState<string>('');
  const [action, setAction] = useState<string>('');
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');
  const [searchQ, setSearchQ] = useState<string>('');

  const { data: tenantsData } = usePlatformTenants();
  const tenants = tenantsData?.tenants ?? [];

  const queryParams: PlatformAuditLogsParams = {
    page: filters.page,
    per_page: filters.per_page ?? 15,
    ...(filters.tenant_id ? { tenant_id: filters.tenant_id } : {}),
    ...(filters.action ? { action: filters.action } : {}),
    ...(filters.from ? { from: filters.from } : {}),
    ...(filters.to ? { to: filters.to } : {}),
    ...(filters.q ? { q: filters.q } : {}),
  };

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['platformAuditLogs', queryParams],
    queryFn: () => platformApi.getAuditLogs(queryParams),
  });

  const applyFilters = useCallback(() => {
    setFilters({
      page: 1,
      per_page: 15,
      tenant_id: tenantId || undefined,
      action: action || undefined,
      from: dateFrom || undefined,
      to: dateTo || undefined,
      q: searchQ?.trim() || undefined,
    });
  }, [tenantId, action, dateFrom, dateTo, searchQ]);

  const clearFilters = useCallback(() => {
    setTenantId('');
    setAction('');
    setDateFrom('');
    setDateTo('');
    setSearchQ('');
    setFilters({ page: 1, per_page: 15 });
  }, []);

  const setPage = useCallback((page: number) => {
    setFilters((prev) => ({ ...prev, page }));
  }, []);

  const logs = data?.data ?? [];
  const meta = data?.meta;

  return (
    <div>
      <h1 className="text-2xl font-semibold text-gray-900 mb-6">Audit Logs</h1>

      <div className="mb-6 p-4 bg-white rounded-lg border border-gray-200 shadow-sm">
        <h2 className="text-sm font-medium text-gray-700 mb-3">Filters</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
          <div>
            <label htmlFor="filter-tenant" className="block text-xs text-gray-500 mb-1">Tenant</label>
            <select
              id="filter-tenant"
              value={tenantId}
              onChange={(e) => setTenantId(e.target.value)}
              className="w-full rounded border border-gray-300 text-sm"
            >
              <option value="">All</option>
              {tenants.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="filter-action" className="block text-xs text-gray-500 mb-1">Action</label>
            <select
              id="filter-action"
              value={action}
              onChange={(e) => setAction(e.target.value)}
              className="w-full rounded border border-gray-300 text-sm"
            >
              <option value="">All</option>
              <option value="platform_login_success">Platform login success</option>
              <option value="platform_login_failure">Platform login failure</option>
              <option value="tenant_login_success">Tenant login success</option>
              <option value="tenant_login_failure">Tenant login failure</option>
              <option value="invitation_created">Invitation created</option>
              <option value="invitation_accepted">Invitation accepted</option>
              <option value="user_role_changed">User role changed</option>
              <option value="impersonation_start">Impersonation start</option>
              <option value="impersonation_stop">Impersonation stop</option>
            </select>
          </div>
          <div>
            <label htmlFor="filter-q" className="block text-xs text-gray-500 mb-1">Search metadata</label>
            <input
              id="filter-q"
              type="text"
              value={searchQ}
              onChange={(e) => setSearchQ(e.target.value)}
              placeholder="Text in metadata"
              className="w-full rounded border border-gray-300 text-sm"
            />
          </div>
          <div>
            <label htmlFor="filter-date-from" className="block text-xs text-gray-500 mb-1">From date</label>
            <input
              id="filter-date-from"
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
              className="w-full rounded border border-gray-300 text-sm"
            />
          </div>
          <div>
            <label htmlFor="filter-date-to" className="block text-xs text-gray-500 mb-1">To date</label>
            <input
              id="filter-date-to"
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
              className="w-full rounded border border-gray-300 text-sm"
            />
          </div>
        </div>
        <div className="mt-3 flex gap-2">
          <button
            type="button"
            onClick={applyFilters}
            className="px-3 py-1.5 text-sm font-medium text-white bg-[#1F6F5C] rounded hover:bg-[#1a6150]"
          >
            Apply
          </button>
          <button
            type="button"
            onClick={clearFilters}
            className="px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded hover:bg-gray-200"
          >
            Clear
          </button>
        </div>
      </div>

      <div className="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
        {isLoading ? (
          <div className="flex justify-center py-12">
            <LoadingSpinner size="lg" />
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tenant</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Actor</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">IP / Agent</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Metadata</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {logs.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-8 text-center text-sm text-gray-500">
                        No audit log entries found.
                      </td>
                    </tr>
                  ) : (
                    logs.map((log: PlatformAuditLogItem) => (
                      <tr key={log.id} className="hover:bg-gray-50">
                        <td className="px-4 py-2 text-sm text-gray-900 whitespace-nowrap">
                          {formatDate(log.created_at)}
                        </td>
                        <td className="px-4 py-2 text-sm text-gray-600">
                          {log.tenant_name ?? log.tenant_id ?? '—'}
                        </td>
                        <td className="px-4 py-2 text-sm">
                          <span className="font-medium text-gray-900">{log.action}</span>
                        </td>
                        <td className="px-4 py-2 text-sm text-gray-600">
                          {log.actor ? `${log.actor.name ?? log.actor.email} (${log.actor.email})` : '—'}
                        </td>
                        <td className="px-4 py-2 text-sm text-gray-500 max-w-[200px] truncate" title={log.user_agent ?? undefined}>
                          {[log.ip, log.user_agent].filter(Boolean).join(' · ') || '—'}
                        </td>
                        <td className="px-4 py-2 text-sm text-gray-500 max-w-xs truncate">
                          {log.metadata ? JSON.stringify(log.metadata) : '—'}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
            {meta && meta.last_page > 1 && (
              <div className="px-4 py-3 border-t border-gray-200 flex items-center justify-between">
                <div className="text-sm text-gray-600">
                  Page {meta.current_page} of {meta.last_page} ({meta.total} total)
                </div>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => setPage(meta.current_page - 1)}
                    disabled={meta.current_page <= 1}
                    className="px-3 py-1 text-sm border border-gray-300 rounded disabled:opacity-50 hover:bg-gray-50"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    onClick={() => setPage(meta.current_page + 1)}
                    disabled={meta.current_page >= meta.last_page}
                    className="px-3 py-1 text-sm border border-gray-300 rounded disabled:opacity-50 hover:bg-gray-50"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
        {isFetching && !isLoading && (
          <div className="absolute inset-0 bg-white/50 flex items-center justify-center">
            <LoadingSpinner size="md" />
          </div>
        )}
      </div>
    </div>
  );
}
