import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { DataTable, type Column } from '../components/DataTable';
import { useFormatting } from '../hooks/useFormatting';
import { useCropCycles } from '../hooks/useCropCycles';
import { Link } from 'react-router-dom';

interface SalesMarginRow {
  sale_id?: string;
  item_id?: string;
  crop_cycle_id?: string;
  qty_sold: string;
  revenue_total: string;
  cogs_total: string;
  gross_margin: string;
  gross_margin_pct: string;
}

export default function SalesMarginPage() {
  const { formatMoney } = useFormatting();
  const { data: cropCycles } = useCropCycles();
  
  const [filters, setFilters] = useState({
    crop_cycle_id: '',
    from: '',
    to: '',
    group_by: 'sale' as 'sale' | 'item' | 'crop_cycle',
  });

  const { data: report, isLoading } = useQuery<SalesMarginRow[]>({
    queryKey: ['sales-margin', filters],
    queryFn: () => {
      const params = new URLSearchParams();
      if (filters.crop_cycle_id) params.append('crop_cycle_id', filters.crop_cycle_id);
      if (filters.from) params.append('from', filters.from);
      if (filters.to) params.append('to', filters.to);
      params.append('group_by', filters.group_by);
      return apiClient.get<SalesMarginRow[]>(`/api/reports/sales-margin?${params.toString()}`);
    },
    enabled: true,
  });

  const columns: Column<SalesMarginRow>[] = [
    ...(filters.group_by === 'sale' ? [
      { 
        header: 'Sale ID', 
        accessor: (row) => row.sale_id ? (
          <Link to={`/app/sales/${row.sale_id}`} className="text-blue-600 hover:text-blue-900">
            {row.sale_id.substring(0, 8)}...
          </Link>
        ) : '-'
      },
    ] : []),
    ...(filters.group_by === 'item' ? [
      { header: 'Item ID', accessor: (row) => row.item_id?.substring(0, 8) || '-' },
    ] : []),
    ...(filters.group_by === 'crop_cycle' ? [
      { header: 'Crop Cycle ID', accessor: (row) => row.crop_cycle_id?.substring(0, 8) || '-' },
    ] : []),
    { 
      header: 'Qty Sold', 
      accessor: (row) => <span className="text-right block">{parseFloat(row.qty_sold).toFixed(3)}</span>
    },
    { 
      header: 'Revenue', 
      accessor: (row) => <span className="text-right block font-semibold">{formatMoney(row.revenue_total)}</span>
    },
    { 
      header: 'COGS', 
      accessor: (row) => <span className="text-right block">{formatMoney(row.cogs_total)}</span>
    },
    { 
      header: 'Gross Margin', 
      accessor: (row) => {
        const margin = parseFloat(row.gross_margin);
        return (
          <span className={`text-right block font-semibold ${margin >= 0 ? 'text-green-600' : 'text-red-600'}`}>
            {formatMoney(row.gross_margin)}
          </span>
        );
      }
    },
    { 
      header: 'Margin %', 
      accessor: (row) => {
        const marginPct = parseFloat(row.gross_margin_pct);
        return (
          <span className={`text-right block font-semibold ${marginPct >= 0 ? 'text-green-600' : 'text-red-600'}`}>
            {marginPct.toFixed(2)}%
          </span>
        );
      }
    },
  ];

  const totals = report ? report.reduce((acc, row) => ({
    qty_sold: acc.qty_sold + parseFloat(row.qty_sold),
    revenue_total: acc.revenue_total + parseFloat(row.revenue_total),
    cogs_total: acc.cogs_total + parseFloat(row.cogs_total),
    gross_margin: acc.gross_margin + parseFloat(row.gross_margin),
  }), { qty_sold: 0, revenue_total: 0, cogs_total: 0, gross_margin: 0 }) : null;

  const totalMarginPct = totals && totals.revenue_total > 0 
    ? (totals.gross_margin / totals.revenue_total) * 100 
    : 0;

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Sales Margin Report</h1>
        <p className="text-sm text-gray-600 mt-1">
          Shows revenue, COGS, and gross margin for sales
        </p>
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Crop Cycle
            </label>
            <select
              value={filters.crop_cycle_id}
              onChange={(e) => setFilters({ ...filters, crop_cycle_id: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Crop Cycles</option>
              {cropCycles?.map((cycle) => (
                <option key={cycle.id} value={cycle.id}>
                  {cycle.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              From Date
            </label>
            <input
              type="date"
              value={filters.from}
              onChange={(e) => setFilters({ ...filters, from: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
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
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Group By
            </label>
            <select
              value={filters.group_by}
              onChange={(e) => setFilters({ ...filters, group_by: e.target.value as 'sale' | 'item' | 'crop_cycle' })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="sale">By Sale</option>
              <option value="item">By Item</option>
              <option value="crop_cycle">By Crop Cycle</option>
            </select>
          </div>
        </div>
      </div>

      {report && (
        <div className="bg-white rounded-lg shadow">
          <div className="p-6">
            {report.length > 0 ? (
              <>
                <DataTable 
                  data={report.map((r, i) => ({ ...r, id: r.sale_id || r.item_id || r.crop_cycle_id || String(i) }))} 
                  columns={columns} 
                />
                
                {totals && (
                  <div className="mt-6 pt-6 border-t border-gray-200">
                    <h3 className="text-md font-medium text-gray-900 mb-4">Totals</h3>
                    <div className="grid grid-cols-5 gap-4">
                      <div>
                        <div className="text-sm text-gray-500">Qty Sold</div>
                        <div className="text-lg font-semibold">{totals.qty_sold.toFixed(3)}</div>
                      </div>
                      <div>
                        <div className="text-sm text-gray-500">Revenue Total</div>
                        <div className="text-lg font-semibold">{formatMoney(totals.revenue_total.toFixed(2))}</div>
                      </div>
                      <div>
                        <div className="text-sm text-gray-500">COGS Total</div>
                        <div className="text-lg font-semibold">{formatMoney(totals.cogs_total.toFixed(2))}</div>
                      </div>
                      <div>
                        <div className="text-sm text-gray-500">Gross Margin</div>
                        <div className={`text-lg font-semibold ${totals.gross_margin >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                          {formatMoney(totals.gross_margin.toFixed(2))}
                        </div>
                      </div>
                      <div>
                        <div className="text-sm text-gray-500">Margin %</div>
                        <div className={`text-lg font-semibold ${totalMarginPct >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                          {totalMarginPct.toFixed(2)}%
                        </div>
                      </div>
                    </div>
                  </div>
                )}
              </>
            ) : (
              <p className="text-gray-500">No sales data found for the selected filters</p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
