import { Link } from 'react-router-dom';
import type { DashboardAlert } from '@farm-erp/shared';

interface AttentionListProps {
  alerts: DashboardAlert[];
}

const severityStyles: Record<string, string> = {
  info: 'border-l-blue-500 bg-blue-50/50',
  warn: 'border-l-amber-500 bg-amber-50/50',
  critical: 'border-l-red-500 bg-red-50/50',
};

export function AttentionList({ alerts }: AttentionListProps) {
  if (alerts.length === 0) {
    return (
      <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
        <h3 className="text-sm font-medium text-gray-600 mb-3">Attention</h3>
        <p className="text-sm text-gray-500">No alerts right now.</p>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
      <h3 className="text-sm font-medium text-gray-600 mb-3">Attention</h3>
      <ul className="space-y-2">
        {alerts.map((alert, i) => (
          <li
            key={i}
            className={`rounded border-l-4 py-2 px-3 text-sm ${severityStyles[alert.severity] ?? severityStyles.info}`}
          >
            <p className="font-medium text-gray-900">{alert.title}</p>
            <p className="text-gray-600 mt-0.5">{alert.detail}</p>
            {alert.action && (
              <Link to={alert.action.to} className="mt-2 inline-block text-sm font-medium text-[#1F6F5C] hover:underline">
                {alert.action.label} →
              </Link>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
