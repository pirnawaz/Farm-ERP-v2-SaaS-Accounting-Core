import type { DashboardSummary } from '@farm-erp/shared';
import { ProfitSnapshotCard } from './ProfitSnapshotCard';
import { MoneySnapshotCard } from './MoneySnapshotCard';
import { FarmSnapshotCard } from './FarmSnapshotCard';
import { GovernanceStatusCard } from './GovernanceStatusCard';
import { AttentionList } from './AttentionList';

interface OwnerLayoutProps {
  data: DashboardSummary;
}

export function OwnerLayout({ data }: OwnerLayoutProps) {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <ProfitSnapshotCard profit={data.profit} />
        <MoneySnapshotCard money={data.money} />
      </div>
      {data.profit.best_project && (
        <div className="grid grid-cols-1">
          <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
            <h3 className="text-sm font-medium text-gray-600 mb-2">Best project</h3>
            <p className="font-semibold text-gray-900">
              {data.profit.best_project.name} — profit this period in scope
            </p>
          </div>
        </div>
      )}
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <FarmSnapshotCard farm={data.farm} scope={data.scope} />
        <GovernanceStatusCard governance={data.governance} />
      </div>
      {data.alerts.length > 0 && (
        <div>
          <AttentionList alerts={data.alerts} />
        </div>
      )}
    </div>
  );
}
