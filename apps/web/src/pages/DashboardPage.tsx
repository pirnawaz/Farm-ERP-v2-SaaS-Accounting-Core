import { Link } from 'react-router-dom';
import { useCropCycles } from '../hooks/useCropCycles';
import { useOperationalTransactions } from '../hooks/useOperationalTransactions';
import { useProjects } from '../hooks/useProjects';
import { StatCard } from '../components/StatCard';
import { LoadingSpinner } from '../components/LoadingSpinner';

export default function DashboardPage() {
  const { data: cropCycles, isLoading: loadingCycles } = useCropCycles();
  const { data: transactions, isLoading: loadingTransactions } = useOperationalTransactions({ status: 'DRAFT' });
  const { data: projects, isLoading: loadingProjects } = useProjects();

  const openCyclesCount = cropCycles?.filter((c) => c.status === 'OPEN').length || 0;
  const draftTransactionsCount = transactions?.length || 0;
  const projectsCount = projects?.length || 0;

  if (loadingCycles || loadingTransactions || loadingProjects) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
      </div>

      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 mb-8">
        <StatCard
          title="Open Crop Cycles"
          value={openCyclesCount}
          link="/app/crop-cycles"
        />
        <StatCard
          title="Draft Transactions"
          value={draftTransactionsCount}
          link="/app/transactions?status=DRAFT"
        />
        <StatCard
          title="Projects"
          value={projectsCount}
          link="/app/projects"
        />
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Quick Actions</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Link
            to="/app/transactions/new"
            className="block p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
          >
            <h3 className="font-medium text-gray-900">New Transaction</h3>
            <p className="text-sm text-gray-600 mt-1">Create a new operational transaction</p>
          </Link>
          <Link
            to="/app/projects"
            className="block p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
          >
            <h3 className="font-medium text-gray-900">View Projects</h3>
            <p className="text-sm text-gray-600 mt-1">Manage your projects</p>
          </Link>
          <Link
            to="/app/reports"
            className="block p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
          >
            <h3 className="font-medium text-gray-900">Reports</h3>
            <p className="text-sm text-gray-600 mt-1">View financial reports</p>
          </Link>
        </div>
      </div>
    </div>
  );
}
