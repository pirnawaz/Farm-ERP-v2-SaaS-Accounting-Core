import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useIssues, useInventoryStores } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { Modal } from '../../components/Modal';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { term } from '../../config/terminology';
import { Badge } from '../../components/Badge';
import { AdvancedWorkflowBanner } from '../../components/workflow/AdvancedWorkflowBanner';
import type { InvIssue } from '../../types';

export default function InvIssuesPage() {
  const [status, setStatus] = useState('');
  const [storeId, setStoreId] = useState('');
  const { data: issues, isLoading } = useIssues({ status: status || undefined, store_id: storeId || undefined });
  const { data: stores } = useInventoryStores();
  const navigate = useNavigate();
  const location = useLocation();
  const { hasRole } = useRole();
  const { formatDate } = useFormatting();
  const [showManualCreate, setShowManualCreate] = useState(false);
  const [manualAck, setManualAck] = useState(false);

  const cols: Column<InvIssue>[] = [
    { header: 'Doc No', accessor: 'doc_no' },
    { header: 'Store', accessor: (r) => r.store?.name || r.store_id },
    { header: 'Project', accessor: (r) => r.project?.name || r.project_id },
    { header: 'Doc Date', accessor: (r) => formatDate(r.doc_date) },
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.status === 'DRAFT' ? 'warning' : r.status === 'POSTED' ? 'success' : 'neutral'}>
          {r.status}
        </Badge>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <AdvancedWorkflowBanner />
      <PageHeader
        title={term('issue')}
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Inventory Overview', to: '/app/inventory' }, { label: term('issue') }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => navigate('/app/crop-ops/field-jobs/new')}
              className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
            >
              New field job
            </button>
            <button
              type="button"
              onClick={() => {
                setManualAck(false);
                setShowManualCreate(true);
              }}
              className="w-full sm:w-auto px-4 py-2 border border-gray-200 bg-white text-gray-800 rounded-md hover:bg-gray-50 text-sm font-medium"
            >
              Record manual stock used
            </button>
          </div>
        ) : undefined}
      />
      <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
        <p className="font-medium">Advanced/manual inventory workflow</p>
        <p className="mt-1 text-amber-900/90">
          Use <span className="font-medium">Field Jobs</span> for normal crop-field work. Only record Stock Used here for
          legacy records or exceptional/manual adjustments that are not part of a Field Job.
        </p>
        <p className="mt-2 text-amber-900/90">
          Recording the same real-world event in both workflows can create duplicate operational and accounting records.
        </p>
      </div>

      <Modal
        isOpen={showManualCreate}
        onClose={() => setShowManualCreate(false)}
        title="Manual / exceptional create path"
      >
        <p className="text-sm text-gray-700">
          For normal crop-field work, record inputs on a Field Job so stock consumption is posted once from one operational
          document.
        </p>
        <label className="mt-4 flex gap-2 text-sm text-gray-800">
          <input
            type="checkbox"
            checked={manualAck}
            onChange={(e) => setManualAck(e.target.checked)}
          />
          I understand this is a manual/exceptional path and may duplicate Field Jobs.
        </label>
        <div className="mt-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
          <button
            type="button"
            onClick={() => setShowManualCreate(false)}
            className="px-4 py-2 border border-gray-200 rounded-md text-sm"
          >
            Cancel
          </button>
          <button
            type="button"
            disabled={!manualAck}
            onClick={() => {
              setShowManualCreate(false);
              navigate('/app/inventory/issues/new?manual_exception_ack=1');
            }}
            className="px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-medium disabled:opacity-40"
          >
            Continue to manual create
          </button>
        </div>
      </Modal>
      <div className="space-y-4">
        <div className="flex flex-wrap gap-4 items-end">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className="px-3 py-2 border rounded text-sm">
            <option value="">All statuses</option>
            <option value="DRAFT">DRAFT</option>
            <option value="POSTED">POSTED</option>
            <option value="REVERSED">REVERSED</option>
          </select>
          <select value={storeId} onChange={(e) => setStoreId(e.target.value)} className="px-3 py-2 border rounded text-sm">
            <option value="">All stores</option>
            {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </div>
        <div className="bg-white rounded-lg shadow">
          {isLoading ? (
            <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
          ) : (
            <DataTable
              data={(issues ?? []) as InvIssue[]}
              columns={cols}
              onRowClick={(r) => navigate(`/app/inventory/issues/${r.id}`, { state: { from: location.pathname + location.search } })}
              emptyMessage="No stock used yet. Record when you take items from storage for field work, a project, or day-to-day farm use."
            />
          )}
        </div>
      </div>
    </div>
  );
}
