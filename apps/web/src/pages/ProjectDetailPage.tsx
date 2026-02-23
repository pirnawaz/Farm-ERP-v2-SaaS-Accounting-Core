import { Link } from 'react-router-dom';
import { useParams } from 'react-router-dom';
import { useProject } from '../hooks/useProjects';
import { useModules } from '../contexts/ModulesContext';
import { LoadingSpinner } from '../components/LoadingSpinner';

export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: project, isLoading } = useProject(id || '');
  const { isModuleEnabled } = useModules();
  const showMachinery = isModuleEnabled('machinery');

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!project) {
    return <div>Project not found</div>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/projects" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Projects
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">{project.name}</h1>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Project Information</h2>
          <dl className="space-y-2">
            <div>
              <dt className="text-sm font-medium text-gray-500">Crop Cycle</dt>
              <dd className="text-sm text-gray-900">{project.crop_cycle?.name || 'N/A'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">HARI</dt>
              <dd className="text-sm text-gray-900">{project.party?.name || 'N/A'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Status</dt>
              <dd className="text-sm text-gray-900">{project.status}</dd>
            </div>
            {project.land_allocation && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Allocated Acres</dt>
                <dd className="text-sm text-gray-900">{project.land_allocation.allocated_acres}</dd>
              </div>
            )}
          </dl>
        </div>

        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Quick Links</h2>
          <div className="space-y-2">
            <Link
              to={`/app/projects/${project.id}/rules`}
              className="block px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-center"
            >
              View/Edit Rules
            </Link>
            <Link
              to={`/app/transactions?project_id=${project.id}`}
              className="block px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 text-center"
            >
              View Transactions
            </Link>
            <Link
              to={`/app/settlement?project_id=${project.id}`}
              className="block px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-center"
            >
              Settlement
            </Link>
          </div>
        </div>

        {showMachinery && (
          <div className="bg-white rounded-lg shadow p-6 lg:col-span-2">
            <h2 className="text-lg font-medium text-gray-900 mb-4">Machinery</h2>
            <p className="text-sm text-gray-500 mb-4">
              Machinery services, work logs and charges linked to this project.
            </p>
            <div className="flex flex-wrap gap-3 items-center">
              <Link
                to={`/app/machinery/services?project_id=${project.id}`}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
              >
                Machinery Services
              </Link>
              <Link
                to={`/app/machinery/work-logs?project_id=${project.id}`}
                className="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700"
              >
                Work Logs
              </Link>
              <Link
                to={`/app/machinery/charges?project_id=${project.id}`}
                className="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700"
              >
                Charges
              </Link>
              <Link
                to={`/app/machinery/services/new?project_id=${project.id}`}
                className="px-4 py-2 border-2 border-[#1F6F5C] text-[#1F6F5C] rounded-md hover:bg-[#1F6F5C] hover:text-white"
              >
                Add Machinery Service
              </Link>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
