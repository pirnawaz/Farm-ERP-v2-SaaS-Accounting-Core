import { useState, useEffect, useRef } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  apiClient,
  type ReconciliationResponse,
  type ReconciliationCheck,
  type Project,
  type CropCycle,
} from '@farm-erp/shared';
import { exportToCSV } from '../utils/csvExport';
import { useFormatting } from '../hooks/useFormatting';
import { PrintableReport } from '../components/print/PrintableReport';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { EmptyState } from '../components/EmptyState';
import { useParties } from '../hooks/useParties';

type TabKey = 'project' | 'crop-cycle' | 'supplier-ap';

const defaultFrom = new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0];
const defaultTo = new Date().toISOString().split('T')[0];

function ReconciliationDashboardPage() {
  const { formatDate } = useFormatting();
  const [searchParams] = useSearchParams();
  const { data: parties = [] } = useParties();
  const [activeTab, setActiveTab] = useState<TabKey>('project');
  const [projects, setProjects] = useState<Project[]>([]);
  const [cropCycles, setCropCycles] = useState<CropCycle[]>([]);
  const [filters, setFilters] = useState({
    project_id: '',
    crop_cycle_id: '',
    party_id: '',
    from: defaultFrom,
    to: defaultTo,
  });
  const [result, setResult] = useState<ReconciliationResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lastRunAt, setLastRunAt] = useState<string | null>(null);
  const [expandedKeys, setExpandedKeys] = useState<Set<string>>(new Set());
  const hasAppliedUrlParams = useRef(false);
  const hasAutoRunForUrl = useRef(false);

  useEffect(() => {
    const fetchOptions = async () => {
      try {
        const [projectsData, cropCyclesData] = await Promise.all([
          apiClient.get<Project[]>('/api/projects'),
          apiClient.get<CropCycle[]>('/api/crop-cycles').catch(() => []),
        ]);
        setProjects(projectsData);
        setCropCycles(cropCyclesData);
      } catch (err) {
        console.error('Failed to fetch options', err);
      }
    };
    fetchOptions();
  }, []);

  // Apply URL params for deep-linking (e.g. from close preview)
  useEffect(() => {
    if (hasAppliedUrlParams.current) return;
    const tab = searchParams.get('tab') as TabKey | null;
    const crop_cycle_id = searchParams.get('crop_cycle_id') ?? '';
    const project_id = searchParams.get('project_id') ?? '';
    const party_id = searchParams.get('party_id') ?? '';
    const from = searchParams.get('from') ?? defaultFrom;
    const to = searchParams.get('to') ?? defaultTo;
    if (tab === 'crop-cycle' && crop_cycle_id && from && to) {
      hasAppliedUrlParams.current = true;
      setActiveTab('crop-cycle');
      setFilters((prev) => ({ ...prev, crop_cycle_id, from, to }));
    } else if (tab === 'project' && project_id && from && to) {
      hasAppliedUrlParams.current = true;
      setActiveTab('project');
      setFilters((prev) => ({ ...prev, project_id, from, to }));
    } else if (tab === 'supplier-ap' && party_id && from && to) {
      hasAppliedUrlParams.current = true;
      setActiveTab('supplier-ap');
      setFilters((prev) => ({ ...prev, party_id, from, to }));
    }
  }, [searchParams]);

  // Auto-run checks when landing with URL params (e.g. from close preview link)
  useEffect(() => {
    if (hasAutoRunForUrl.current || !hasAppliedUrlParams.current) return;
    const tab = searchParams.get('tab');
    const crop_cycle_id = searchParams.get('crop_cycle_id');
    const project_id = searchParams.get('project_id');
    const party_id = searchParams.get('party_id');
    const from = searchParams.get('from');
    const to = searchParams.get('to');
    if (!from || !to) return;
    if (tab === 'crop-cycle' && crop_cycle_id) {
      hasAutoRunForUrl.current = true;
      setLoading(true);
      setError(null);
      setResult(null);
      apiClient
        .reconcileCropCycle({ crop_cycle_id, from, to })
        .then((data) => {
          setResult(data);
          setLastRunAt(new Date().toISOString());
        })
        .catch((err) => setError(err instanceof Error ? err.message : 'Failed to run reconciliation checks'))
        .finally(() => setLoading(false));
    } else if (tab === 'project' && project_id) {
      hasAutoRunForUrl.current = true;
      setLoading(true);
      setError(null);
      setResult(null);
      apiClient
        .reconcileProject({ project_id, from, to })
        .then((data) => {
          setResult(data);
          setLastRunAt(new Date().toISOString());
        })
        .catch((err) => setError(err instanceof Error ? err.message : 'Failed to run reconciliation checks'))
        .finally(() => setLoading(false));
    } else if (tab === 'supplier-ap' && party_id) {
      hasAutoRunForUrl.current = true;
      setLoading(true);
      setError(null);
      setResult(null);
      apiClient
        .reconcileSupplierAp({ party_id, from, to })
        .then((data) => {
          setResult(data);
          setLastRunAt(new Date().toISOString());
        })
        .catch((err) => setError(err instanceof Error ? err.message : 'Failed to run reconciliation checks'))
        .finally(() => setLoading(false));
    }
  }, [searchParams]);

  const runChecks = async () => {
    if (!filters.from || !filters.to) {
      setError('From and To dates are required.');
      return;
    }
    setLoading(true);
    setError(null);
    setResult(null);
    try {
      let data: ReconciliationResponse;
      if (activeTab === 'project') {
        if (!filters.project_id) {
          setError('Please select a project.');
          setLoading(false);
          return;
        }
        data = await apiClient.reconcileProject({
          project_id: filters.project_id,
          from: filters.from,
          to: filters.to,
        });
      } else if (activeTab === 'crop-cycle') {
        if (!filters.crop_cycle_id) {
          setError('Please select a crop cycle.');
          setLoading(false);
          return;
        }
        data = await apiClient.reconcileCropCycle({
          crop_cycle_id: filters.crop_cycle_id,
          from: filters.from,
          to: filters.to,
        });
      } else {
        if (!filters.party_id) {
          setError('Please select a party (supplier).');
          setLoading(false);
          return;
        }
        data = await apiClient.reconcileSupplierAp({
          party_id: filters.party_id,
          from: filters.from,
          to: filters.to,
        });
      }
      setResult(data);
      setLastRunAt(new Date().toISOString());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to run reconciliation checks');
    } finally {
      setLoading(false);
    }
  };

  const toggleExpanded = (key: string) => {
    setExpandedKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const handleExport = () => {
    if (!result?.checks?.length) return;
    const detailKeys = getDetailKeys(result.checks);
    const headers = ['key', 'title', 'status', 'summary', ...detailKeys];
    const rows = result.checks.map((c) => {
      const flat = flattenDetails(c.details);
      const row: Record<string, string | number> = {
        key: c.key,
        title: c.title,
        status: c.status,
        summary: c.summary,
      };
      detailKeys.forEach((k) => {
        row[k] = flat[k] ?? '';
      });
      return row;
    });
    exportToCSV(rows, '', headers, {
      reportName: 'ReconciliationDashboard',
      fromDate: filters.from,
      toDate: filters.to,
    });
  };

  const scopeLabel =
    activeTab === 'project'
      ? projects.find((p) => p.id === filters.project_id)?.name ?? 'Project'
      : activeTab === 'crop-cycle'
        ? cropCycles.find((c) => c.id === filters.crop_cycle_id)?.name ?? 'Crop Cycle'
        : parties.find((p) => p.id === filters.party_id)?.name ?? 'Supplier AP';

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center no-print">
        <h2 className="text-2xl font-bold">Reconciliation Dashboard</h2>
        <div className="flex gap-2">
          <button
            onClick={() => window.print()}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Print
          </button>
          <button
            onClick={handleExport}
            disabled={!result?.checks?.length}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
          >
            Export CSV
          </button>
        </div>
      </div>

      <div className="border-b border-gray-200 mb-6 no-print">
        <nav className="-mb-px flex space-x-8">
          {(['project', 'crop-cycle', 'supplier-ap'] as const).map((tab) => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab)}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === tab
                  ? 'border-[#1F6F5C] text-[#1F6F5C]'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {tab === 'project' ? 'Project' : tab === 'crop-cycle' ? 'Crop Cycle' : 'Supplier AP'}
            </button>
          ))}
        </nav>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4 no-print">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {activeTab === 'project' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Project</label>
              <select
                value={filters.project_id}
                onChange={(e) => setFilters({ ...filters, project_id: e.target.value })}
                className="w-full border border-gray-300 rounded px-3 py-2"
              >
                <option value="">Select Project</option>
                {projects.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </div>
          )}
          {activeTab === 'crop-cycle' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Crop Cycle</label>
              <select
                value={filters.crop_cycle_id}
                onChange={(e) => setFilters({ ...filters, crop_cycle_id: e.target.value })}
                className="w-full border border-gray-300 rounded px-3 py-2"
              >
                <option value="">Select Crop Cycle</option>
                {cropCycles.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </div>
          )}
          {activeTab === 'supplier-ap' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Party (Supplier)</label>
              <select
                value={filters.party_id}
                onChange={(e) => setFilters({ ...filters, party_id: e.target.value })}
                className="w-full border border-gray-300 rounded px-3 py-2"
              >
                <option value="">Select Party</option>
                {parties.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </div>
          )}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">From</label>
            <input
              type="date"
              value={filters.from}
              onChange={(e) => setFilters({ ...filters, from: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">To</label>
            <input
              type="date"
              value={filters.to}
              onChange={(e) => setFilters({ ...filters, to: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
        </div>
        <div>
          <button
            onClick={runChecks}
            disabled={loading}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
          >
            {loading ? 'Running…' : 'Run Checks'}
          </button>
        </div>
        {error && <p className="text-red-600 text-sm">{error}</p>}
        {lastRunAt && !loading && (
          <p className="text-gray-500 text-sm">Last run: {formatDate(lastRunAt)}</p>
        )}
      </div>

      {loading && (
        <div className="flex justify-center py-8 no-print">
          <LoadingSpinner size="lg" />
        </div>
      )}

      {!loading && result && (
        <>
          <div className="no-print">
            <p className="text-gray-600 text-sm mb-2">Generated at: {formatDate(result.generated_at)}</p>
            <div className="space-y-4">
              {result.checks.map((check) => (
                <CheckCard
                  key={check.key}
                  check={check}
                  expanded={expandedKeys.has(check.key)}
                  onToggle={() => toggleExpanded(check.key)}
                />
              ))}
            </div>
          </div>
          <PrintableReport
            title="Reconciliation Dashboard"
            subtitle={scopeLabel}
            metaLeft={`From: ${filters.from} To: ${filters.to}`}
            metaRight={`Generated: ${result.generated_at}`}
          >
            <p className="text-sm text-gray-600 mb-2">Generated at: {result.generated_at}</p>
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b">
                  <th className="text-left py-2">Check</th>
                  <th className="text-left py-2">Status</th>
                  <th className="text-left py-2">Summary</th>
                </tr>
              </thead>
              <tbody>
                {result.checks.map((check) => (
                  <tr key={check.key} className="border-b">
                    <td className="py-2">{check.title}</td>
                    <td className="py-2">{check.status}</td>
                    <td className="py-2">{check.summary}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </PrintableReport>
        </>
      )}

      {!loading && !result && !error && (
        <EmptyState
          title="No results yet"
          description="Select scope and dates, then click Run Checks."
        />
      )}
    </div>
  );
}

function CheckCard({
  check,
  expanded,
  onToggle,
}: {
  check: ReconciliationCheck;
  expanded: boolean;
  onToggle: () => void;
}) {
  const statusColor =
    check.status === 'PASS' ? 'bg-green-100 text-green-800' : check.status === 'WARN' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800';
  return (
    <div className="border border-gray-200 rounded-lg overflow-hidden">
      <button
        type="button"
        onClick={onToggle}
        className="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50"
      >
        <div className="flex items-center gap-3">
          <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColor}`}>
            {check.status}
          </span>
          <span className="font-medium text-gray-900">{check.title}</span>
          <span className="text-gray-600 text-sm">{check.summary}</span>
        </div>
        <span className="text-gray-400">{expanded ? '▼' : '▶'}</span>
      </button>
      {expanded && (
        <div className="px-4 pb-4 pt-0 border-t border-gray-100">
          <pre className="text-xs bg-gray-50 p-3 rounded overflow-auto max-h-48">
            {JSON.stringify(check.details, null, 2)}
          </pre>
        </div>
      )}
    </div>
  );
}

function flattenDetails(details: Record<string, unknown>): Record<string, string | number> {
  const out: Record<string, string | number> = {};
  Object.entries(details).forEach(([k, v]) => {
    if (v !== null && v !== undefined) out[k] = typeof v === 'object' ? JSON.stringify(v) : (v as string | number);
  });
  return out;
}

function getDetailKeys(checks: ReconciliationCheck[]): string[] {
  const set = new Set<string>();
  checks.forEach((c) => Object.keys(c.details).forEach((k) => set.add(k)));
  return Array.from(set);
}

export default ReconciliationDashboardPage;
