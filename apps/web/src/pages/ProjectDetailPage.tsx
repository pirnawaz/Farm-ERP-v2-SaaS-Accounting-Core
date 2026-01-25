import { Link } from 'react-router-dom';
import { useParams } from 'react-router-dom';
import { useProject } from '../hooks/useProjects';
import { LoadingSpinner } from '../components/LoadingSpinner';

export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: project, isLoading } = useProject(id || '');

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
        <Link to="/app/projects" className="text-blue-600 hover:text-blue-900 mb-2 inline-block">
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
              className="block px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-center"
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
      </div>
    </div>
  );
}
