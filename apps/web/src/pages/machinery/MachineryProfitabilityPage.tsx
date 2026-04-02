import { useState } from 'react';
import { useMachineryProfitabilityQuery } from '../../hooks/useMachinery';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { useFormatting } from '../../hooks/useFormatting';
import { Modal } from '../../components/Modal';
import type { MachineryProfitabilityRow, MachineryChargesByMachineRow, MachineryCostsByMachineRow } from '../../types';
import { machineryApi } from '../../api/machinery';
import { useQuery } from '@tanstack/react-query';
import { ReportEmptyStateCard } from '../../components/report/ReportStates';
import { EMPTY_COPY } from '../../config/presentation';

export default function MachineryProfitabilityPage() {
  const { formatMoney } = useFormatting();
  const [filters, setFilters] = useState({
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
  });

  const [selectedMachine, setSelectedMachine] = useState<MachineryProfitabilityRow | null>(null);
  const [showDrilldown, setShowDrilldown] = useState(false);

  const { data: report, isLoading, error } = useMachineryProfitabilityQuery(filters);

  // Fetch drilldown data when a machine is selected
  const { data: chargesData } = useQuery<MachineryChargesByMachineRow[]>({
    queryKey: ['machinery', 'reports', 'charges-by-machine', filters, selectedMachine?.machine_id],
    queryFn: () => machineryApi.reports.chargesByMachine(filters),
    enabled: showDrilldown && !!selectedMachine,
    select: (data) => data.filter((row) => row.machine_id === selectedMachine?.machine_id),
  });

  const { data: costsData } = useQuery<MachineryCostsByMachineRow[]>({
    queryKey: ['machinery', 'reports', 'costs-by-machine', filters, selectedMachine?.machine_id],
    queryFn: () => machineryApi.reports.costsByMachine(filters),
    enabled: showDrilldown && !!selectedMachine,
    select: (data) => data.filter((row) => row.machine_id === selectedMachine?.machine_id),
  });

  const handleRowClick = (row: MachineryProfitabilityRow) => {
    setSelectedMachine(row);
    setShowDrilldown(true);
  };

  const formatQuantity = (qty: string | null | undefined): string => {
    if (qty === null || qty === undefined) return '—';
    const num = parseFloat(qty);
    if (isNaN(num)) return '—';
    return num.toFixed(2);
  };

  const columns: Column<MachineryProfitabilityRow>[] = [
    {
      header: 'Machine',
      accessor: (row) => (
        <div>
          <div className="font-medium text-gray-900">{row.machine_code}</div>
          <div className="text-sm text-gray-500">{row.machine_name}</div>
        </div>
      ),
    },
    {
      header: 'Unit',
      accessor: (row) => <span className="text-gray-900">{row.unit || '—'}</span>,
    },
    {
      header: 'Usage Qty',
      accessor: (row) => (
        <span className="text-right block tabular-nums">{formatQuantity(row.usage_qty)}</span>
      ),
    },
    {
      header: 'Charges',
      accessor: (row) => (
        <span className="text-right block font-semibold tabular-nums">
          {formatMoney(parseFloat(row.charges_total))}
        </span>
      ),
    },
    {
      header: 'Costs',
      accessor: (row) => (
        <span className="text-right block tabular-nums">
          {formatMoney(parseFloat(row.costs_total))}
        </span>
      ),
    },
    {
      header: 'Margin',
      accessor: (row) => {
        const margin = parseFloat(row.margin);
        return (
          <span
            className={`text-right block font-semibold tabular-nums ${
              margin >= 0 ? 'text-green-600' : 'text-red-600'
            }`}
          >
            {formatMoney(margin)}
          </span>
        );
      },
    },
    {
      header: 'Charge / Unit',
      accessor: (row) => (
        <span className="text-right block tabular-nums">
          {row.charge_per_unit !== null ? formatMoney(parseFloat(row.charge_per_unit)) : '—'}
        </span>
      ),
    },
    {
      header: 'Cost / Unit',
      accessor: (row) => (
        <span className="text-right block tabular-nums">
          {row.cost_per_unit !== null ? formatMoney(parseFloat(row.cost_per_unit)) : '—'}
        </span>
      ),
    },
    {
      header: 'Margin / Unit',
      accessor: (row) => {
        if (row.margin_per_unit === null) return <span className="text-right block">—</span>;
        const marginPerUnit = parseFloat(row.margin_per_unit);
        return (
          <span
            className={`text-right block font-semibold tabular-nums ${
              marginPerUnit >= 0 ? 'text-green-600' : 'text-red-600'
            }`}
          >
            {formatMoney(marginPerUnit)}
          </span>
        );
      },
    },
  ];

  const totals = report
    ? report.reduce(
        (acc, row) => ({
          usage_qty: acc.usage_qty + parseFloat(row.usage_qty),
          charges_total: acc.charges_total + parseFloat(row.charges_total),
          costs_total: acc.costs_total + parseFloat(row.costs_total),
          margin: acc.margin + parseFloat(row.margin),
        }),
        { usage_qty: 0, charges_total: 0, costs_total: 0, margin: 0 }
      )
    : null;

  if (error) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="Machinery Profitability Report"
          backTo="/app/machinery"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery', to: '/app/machinery' },
            { label: 'Profitability' },
          ]}
        />
        <div className="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
          {error instanceof Error ? error.message : 'Failed to load profitability report'}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Machinery Profitability Report"
        backTo="/app/machinery"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'Profitability' },
        ]}
      />
      <p className="text-sm text-gray-600">
        Shows usage, charges, costs, and margins per machine
      </p>

      <div className="bg-white rounded-lg shadow p-6">
        <div className="flex flex-wrap gap-4 items-end">
          <div className="flex flex-col gap-1 min-w-[10rem]">
            <label className="text-sm font-medium text-gray-700">From Date</label>
            <input
              type="date"
              value={filters.from}
              onChange={(e) => setFilters({ ...filters, from: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
          <div className="flex flex-col gap-1 min-w-[10rem]">
            <label className="text-sm font-medium text-gray-700">To Date</label>
            <input
              type="date"
              value={filters.to}
              onChange={(e) => setFilters({ ...filters, to: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow overflow-x-auto">
        <div className="p-6">
          {isLoading ? (
            <div className="flex justify-center py-12">
              <LoadingSpinner size="lg" />
            </div>
          ) : report && report.length > 0 ? (
            <>
              <DataTable
                data={report.map((r, i) => ({ ...r, id: r.machine_id || String(i) }))}
                columns={columns}
                onRowClick={handleRowClick}
              />

              {totals && (
                <div className="mt-6 pt-6 border-t border-gray-200">
                  <h3 className="text-md font-medium text-gray-900 mb-4">Totals</h3>
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                      <div className="text-sm text-gray-500">Total Usage</div>
                      <div className="text-lg font-semibold tabular-nums">
                        {totals.usage_qty.toFixed(2)}
                      </div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">Total Charges</div>
                      <div className="text-lg font-semibold tabular-nums">
                        {formatMoney(totals.charges_total.toFixed(2))}
                      </div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">Total Costs</div>
                      <div className="text-lg font-semibold tabular-nums">
                        {formatMoney(totals.costs_total.toFixed(2))}
                      </div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">Total Margin</div>
                      <div
                        className={`text-lg font-semibold tabular-nums ${
                          totals.margin >= 0 ? 'text-green-600' : 'text-red-600'
                        }`}
                      >
                        {formatMoney(totals.margin.toFixed(2))}
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </>
          ) : (
            <ReportEmptyStateCard message={EMPTY_COPY.noDataForPeriod} className="shadow-none border border-gray-100" />
          )}
        </div>
      </div>

      {/* Drilldown Modal */}
      {showDrilldown && selectedMachine && (
        <Modal
          isOpen={showDrilldown}
          onClose={() => {
            setShowDrilldown(false);
            setSelectedMachine(null);
          }}
          title={`${selectedMachine.machine_code} - ${selectedMachine.machine_name} Details`}
          size="lg"
        >
          <div className="space-y-6">
            {/* Charges Section */}
            <div>
              <h3 className="text-lg font-medium text-gray-900 mb-3">Charges</h3>
              {chargesData && chargesData.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                          Unit
                        </th>
                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                          Usage Qty
                        </th>
                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                          Charges Total
                        </th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                      {chargesData.map((row) => (
                        <tr key={row.machine_id}>
                          <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                            {row.unit}
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                            {formatQuantity(row.usage_qty)}
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-gray-900 text-right tabular-nums">
                            {formatMoney(parseFloat(row.charges_total))}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-gray-500 text-sm">No charges data available</p>
              )}
            </div>

            {/* Costs Section */}
            <div>
              <h3 className="text-lg font-medium text-gray-900 mb-3">Costs Breakdown</h3>
              {costsData && costsData.length > 0 ? (
                <div className="space-y-4">
                  {costsData.map((row) => (
                    <div key={row.machine_id}>
                      <div className="mb-2">
                        <span className="text-sm font-medium text-gray-700">Total Costs: </span>
                        <span className="text-sm font-semibold text-gray-900 tabular-nums">
                          {formatMoney(parseFloat(row.costs_total))}
                        </span>
                      </div>
                      {row.breakdown && row.breakdown.length > 0 && (
                        <div className="overflow-x-auto">
                          <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                              <tr>
                                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                  Allocation Type
                                </th>
                                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                  Amount
                                </th>
                              </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                              {row.breakdown.map((item, idx) => (
                                <tr key={idx}>
                                  <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                    {item.key}
                                  </td>
                                  <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                                    {formatMoney(parseFloat(item.amount))}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-gray-500 text-sm">No costs data available</p>
              )}
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
