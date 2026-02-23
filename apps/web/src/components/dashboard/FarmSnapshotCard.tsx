import { Link } from 'react-router-dom';
import type { DashboardFarm, DashboardScope } from '@farm-erp/shared';

interface FarmSnapshotCardProps {
  farm: DashboardFarm;
  scope: DashboardScope;
}

export function FarmSnapshotCard({ farm, scope }: FarmSnapshotCardProps) {
  return (
    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
      <h3 className="text-sm font-medium text-gray-600 mb-3">Farm at a glance</h3>
      <p className="text-xs text-gray-500 mb-4">Scope: {scope.label}</p>
      <dl className="grid grid-cols-2 gap-3 text-sm">
        <div>
          <dt className="text-gray-500">Open crop cycles</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">
            <Link to="/app/crop-cycles" className="text-[#1F6F5C] hover:underline">
              {farm.active_crop_cycles_count}
            </Link>
          </dd>
        </div>
        <div>
          <dt className="text-gray-500">Active projects</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">
            <Link to="/app/projects" className="text-[#1F6F5C] hover:underline">
              {farm.open_projects_count}
            </Link>
          </dd>
        </div>
        <div>
          <dt className="text-gray-500">Harvests (this cycle)</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{farm.harvests_this_cycle_count}</dd>
        </div>
        <div>
          <dt className="text-gray-500">Unposted records</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{farm.unposted_records_count}</dd>
        </div>
      </dl>
    </div>
  );
}
