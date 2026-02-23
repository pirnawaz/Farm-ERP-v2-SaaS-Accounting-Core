import { Link } from 'react-router-dom';
import type { DashboardGovernance } from '@farm-erp/shared';

interface GovernanceStatusCardProps {
  governance: DashboardGovernance;
}

export function GovernanceStatusCard({ governance }: GovernanceStatusCardProps) {
  return (
    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
      <h3 className="text-sm font-medium text-gray-600 mb-3">Governance</h3>
      <dl className="space-y-2 text-sm">
        <div className="flex justify-between">
          <dt className="text-gray-500">Settlements pending</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{governance.settlements_pending_count}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-gray-500">Cycles closed</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{governance.cycles_closed_count}</dd>
        </div>
      </dl>
      {governance.locks_warning.length > 0 && (
        <div className="mt-3 pt-3 border-t border-gray-100">
          <p className="text-xs text-gray-500 mb-1">Closed periods</p>
          <ul className="text-xs text-gray-600 space-y-0.5">
            {governance.locks_warning.slice(0, 3).map((w, i) => (
              <li key={i}>
                {w.label} — {w.date}
              </li>
            ))}
          </ul>
        </div>
      )}
      <Link to="/app/reports/trial-balance" className="mt-4 inline-block text-sm font-medium text-[#1F6F5C] hover:underline">
        Trial balance →
      </Link>
    </div>
  );
}
