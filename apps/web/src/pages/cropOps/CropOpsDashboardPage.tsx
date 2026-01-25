import { Link } from 'react-router-dom';

export default function CropOpsDashboardPage() {
  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Crop Ops</h1>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <Link to="/app/crop-ops/activity-types" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-blue-300">
          <span className="font-medium text-gray-900">Activity Types</span>
          <p className="text-sm text-gray-500">Manage activity types</p>
        </Link>
        <Link to="/app/crop-ops/activities" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-blue-300">
          <span className="font-medium text-gray-900">Activities</span>
          <p className="text-sm text-gray-500">View and manage activities</p>
        </Link>
        <Link to="/app/crop-ops/activities/new" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-blue-300">
          <span className="font-medium text-gray-900">New Activity</span>
          <p className="text-sm text-gray-500">Create a new activity</p>
        </Link>
      </div>
    </div>
  );
}
