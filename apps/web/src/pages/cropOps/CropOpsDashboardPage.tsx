import { Link } from 'react-router-dom';
import { term } from '../../config/terminology';

export default function CropOpsDashboardPage() {
  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Crop Ops</h1>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <Link to="/app/crop-ops/activity-types" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('activityType')}</span>
          <p className="text-sm text-gray-500">Manage {term('activityType').toLowerCase()}</p>
        </Link>
        <Link to="/app/crop-ops/activities" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('activities')}</span>
          <p className="text-sm text-gray-500">View and manage {term('activities').toLowerCase()}</p>
        </Link>
        <Link to="/app/crop-ops/activities/new" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('newActivity')}</span>
          <p className="text-sm text-gray-500">Create new field work</p>
        </Link>
      </div>
    </div>
  );
}
