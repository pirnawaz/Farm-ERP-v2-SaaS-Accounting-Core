import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useIssues, useInventoryStores } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useRole } from '../../hooks/useRole';
import type { InvIssue } from '../../types';

export default function InvIssuesPage() {
  const [status, setStatus] = useState('');
  const [storeId, setStoreId] = useState('');
  const { data: issues, isLoading } = useIssues({ status: status || undefined, store_id: storeId || undefined });
  const { data: stores } = useInventoryStores();
  const navigate = useNavigate();
  const { hasRole } = useRole();

  const cols: Column<InvIssue>[] = [
    { header: 'Doc No', accessor: 'doc_no' },
    { header: 'Store', accessor: (r) => r.store?.name || r.store_id },
    { header: 'Project', accessor: (r) => r.project?.name || r.project_id },
    { header: 'Doc Date', accessor: 'doc_date' },
    { header: 'Status', accessor: (r) => (
      <span className={`px-2 py-1 rounded text-xs ${
        r.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
        r.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
      }`}>{r.status}</span>
    ) },
  ];

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Issues</h1>
        {hasRole(['tenant_admin', 'accountant', 'operator']) && (
          <button onClick={() => navigate('/app/inventory/issues/new')} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">New Issue</button>
        )}
      </div>
      <div className="flex gap-4 mb-4">
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
        <DataTable
          data={issues || []}
          columns={cols}
          onRowClick={(r) => navigate(`/app/inventory/issues/${r.id}`)}
          emptyMessage="No issues. Create one."
        />
      </div>
    </div>
  );
}
