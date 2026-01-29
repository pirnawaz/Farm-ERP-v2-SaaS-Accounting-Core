import { Link } from 'react-router-dom';
import { useWorkers, useWorkLogs, usePayablesOutstanding } from '../../hooks/useLabour';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';

export default function LabourDashboardPage() {
  const { data: workers, isLoading: workersLoading } = useWorkers();
  const { data: workLogs, isLoading: logsLoading } = useWorkLogs();
  const { data: payables, isLoading: payablesLoading } = usePayablesOutstanding();
  const { formatMoney } = useFormatting();

  const workerCount = (workers ?? []).length;
  const logCount = (workLogs ?? []).length;
  const outstandingTotal = (payables || []).reduce((s, r) => s + parseFloat(r.payable_balance || '0'), 0);

  if (workersLoading || logsLoading || payablesLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Labour</h1>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <Link to="/app/labour/workers" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">Workers</span>
          <p className="text-sm text-gray-500">{workerCount} workers</p>
        </Link>
        <Link to="/app/labour/work-logs" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">Work Logs</span>
          <p className="text-sm text-gray-500">{logCount} logs</p>
        </Link>
        <Link to="/app/labour/payables" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">Payables</span>
          <p className="text-sm text-gray-500"><span className="tabular-nums">{formatMoney(outstandingTotal)}</span> outstanding</p>
        </Link>
      </div>
    </div>
  );
}
