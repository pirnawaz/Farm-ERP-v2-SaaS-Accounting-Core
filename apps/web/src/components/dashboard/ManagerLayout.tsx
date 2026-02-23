import type { DashboardSummary } from '@farm-erp/shared';
import { FarmSnapshotCard } from './FarmSnapshotCard';
import { AttentionList } from './AttentionList';
import { MoneySnapshotCard } from './MoneySnapshotCard';
import { ProfitSnapshotCard } from './ProfitSnapshotCard';
import { GovernanceStatusCard } from './GovernanceStatusCard';

interface ManagerLayoutProps {
  data: DashboardSummary;
}

export function ManagerLayout({ data }: ManagerLayoutProps) {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <FarmSnapshotCard farm={data.farm} scope={data.scope} />
        <AttentionList alerts={data.alerts} />
      </div>
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <MoneySnapshotCard money={data.money} />
        <ProfitSnapshotCard profit={data.profit} />
        <GovernanceStatusCard governance={data.governance} />
      </div>
    </div>
  );
}
