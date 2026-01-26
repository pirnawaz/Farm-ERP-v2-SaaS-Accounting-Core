import { useEffect, useState } from 'react';
import { apiClient } from '@farm-erp/shared';
import { exportToCSV } from '../utils/csvExport';
import { useFormatting } from '../hooks/useFormatting';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { DataTable, type Column } from '../components/DataTable';

interface CashbookRow {
  date: string;
  description: string;
  reference: string;
  type: 'IN' | 'OUT';
  amount: string;
  source_type: string;
  source_id: string;
}

export default function CashbookPage() {
  const { formatMoney } = useFormatting();
  const [data, setData] = useState<CashbookRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  
  const [filters, setFilters] = useState({
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
  });

  useEffect(() => {
    const fetchData = async () => {
      if (!filters.from || !filters.to) return;
      
      try {
        setLoading(true);
        setError(null);
        
        const result = await apiClient.get<CashbookRow[]>(`/api/reports/cashbook?from=${filters.from}&to=${filters.to}`);
        setData(result);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch cashbook');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [filters]);

  const handleExport = () => {
    exportToCSV(
      data,
      `cashbook-${filters.from}-to-${filters.to}.csv`,
      ['date', 'description', 'reference', 'type', 'amount']
    );
  };

  const columns: Column<CashbookRow>[] = [
    { header: 'Date', accessor: 'date' },
    { header: 'Description', accessor: 'description' },
    { header: 'Reference', accessor: 'reference' },
    { 
      header: 'Type', 
      accessor: 'type',
      render: (value) => (
        <span className={`px-2 py-1 rounded text-xs ${
          value === 'IN' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
        }`}>
          {value}
        </span>
      )
    },
    { 
      header: 'Amount', 
      accessor: 'amount',
      render: (value, row) => formatMoney(parseFloat(value || '0'))
    },
  ];

  const totalIn = data
    .filter(row => row.type === 'IN')
    .reduce((sum, row) => sum + parseFloat(row.amount || '0'), 0);
  
  const totalOut = data
    .filter(row => row.type === 'OUT')
    .reduce((sum, row) => sum + parseFloat(row.amount || '0'), 0);
  
  const netBalance = totalIn - totalOut;

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-2xl font-bold">Cashbook</h2>
        <button
          onClick={handleExport}
          disabled={data.length === 0}
          className="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
        >
          Export CSV
        </button>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              From Date
            </label>
            <input
              type="date"
              value={filters.from}
              onChange={(e) => setFilters({ ...filters, from: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              To Date
            </label>
            <input
              type="date"
              value={filters.to}
              onChange={(e) => setFilters({ ...filters, to: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
        </div>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded">
          {error}
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : (
        <>
          <div className="bg-white rounded-lg shadow">
            <DataTable 
              data={data.map((r, i) => ({ ...r, id: r.source_id || String(i) }))} 
              columns={columns} 
            />
            {data.length > 0 && (
              <div className="p-4 bg-gray-50 border-t">
                <div className="grid grid-cols-4 gap-4 text-sm font-medium">
                  <div>Total In: {formatMoney(totalIn)}</div>
                  <div>Total Out: {formatMoney(totalOut)}</div>
                  <div>Net Balance: {formatMoney(netBalance)}</div>
                  <div>Transactions: {data.length}</div>
                </div>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
