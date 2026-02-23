import { Link } from 'react-router-dom';
import type { DashboardSummary } from '@farm-erp/shared';
import { GovernanceStatusCard } from './GovernanceStatusCard';
import { MoneySnapshotCard } from './MoneySnapshotCard';
import { AttentionList } from './AttentionList';
import { FarmSnapshotCard } from './FarmSnapshotCard';
import { ProfitSnapshotCard } from './ProfitSnapshotCard';

interface AccountantLayoutProps {
  data: DashboardSummary;
}

export function AccountantLayout({ data }: AccountantLayoutProps) {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <GovernanceStatusCard governance={data.governance} />
        <MoneySnapshotCard money={data.money} />
      </div>
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
          <h3 className="text-sm font-medium text-gray-600 mb-3">Reports</h3>
          <ul className="space-y-2 text-sm">
            <li>
              <Link to="/app/reports/trial-balance" className="text-[#1F6F5C] hover:underline">
                Trial balance →
              </Link>
            </li>
            <li>
              <Link to="/app/reports/reconciliation-dashboard" className="text-[#1F6F5C] hover:underline">
                Reconciliation →
              </Link>
            </li>
            <li>
              <Link to="/app/reports/ar-ageing" className="text-[#1F6F5C] hover:underline">
                AR ageing →
              </Link>
            </li>
          </ul>
        </div>
        <AttentionList alerts={data.alerts} />
      </div>
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <FarmSnapshotCard farm={data.farm} scope={data.scope} />
        <ProfitSnapshotCard profit={data.profit} />
      </div>
    </div>
  );
}
