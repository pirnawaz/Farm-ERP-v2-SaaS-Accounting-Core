import { Link } from 'react-router-dom';
import { useFormatting } from '../../hooks/useFormatting';
import type { DashboardProfit } from '@farm-erp/shared';
import { term } from '../../config/terminology';

interface ProfitSnapshotCardProps {
  profit: DashboardProfit;
}

export function ProfitSnapshotCard({ profit }: ProfitSnapshotCardProps) {
  const { formatMoney } = useFormatting();

  return (
    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
      <h3 className="text-sm font-medium text-gray-600 mb-3">Profit</h3>
      <dl className="space-y-2 text-sm">
        {profit.profit_this_cycle !== null && (
          <div className="flex justify-between">
            <dt className="text-gray-500">This cycle</dt>
            <dd className="font-semibold text-gray-900 tabular-nums">{formatMoney(profit.profit_this_cycle)}</dd>
          </div>
        )}
        <div className="flex justify-between">
          <dt className="text-gray-500">YTD</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{formatMoney(profit.profit_ytd)}</dd>
        </div>
        {profit.best_project && (
          <div className="mt-3 pt-3 border-t border-gray-100">
            <dt className="text-gray-500 text-xs">Best {term('fieldCycle').toLowerCase()}</dt>
            <dd className="font-semibold text-gray-900">
              <Link to={`/app/projects/${profit.best_project.project_id}`} className="text-[#1F6F5C] hover:underline">
                {profit.best_project.name}
              </Link>
              <span className="tabular-nums ml-1">({formatMoney(profit.best_project.profit)})</span>
            </dd>
          </div>
        )}
        {profit.cost_per_acre !== null && (
          <div className="flex justify-between mt-2">
            <dt className="text-gray-500">Cost per acre</dt>
            <dd className="font-semibold text-gray-900 tabular-nums">{formatMoney(profit.cost_per_acre)}</dd>
          </div>
        )}
      </dl>
      <Link to="/app/reports/profit-loss" className="mt-4 inline-block text-sm font-medium text-[#1F6F5C] hover:underline">
        View P&L →
      </Link>
    </div>
  );
}
