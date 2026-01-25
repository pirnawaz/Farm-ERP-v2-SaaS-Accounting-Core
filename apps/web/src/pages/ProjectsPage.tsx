import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useProjects, useCreateProjectFromAllocation } from '../hooks/useProjects';
import { useLandAllocations } from '../hooks/useLandAllocations';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import toast from 'react-hot-toast';
import type { Project, CreateProjectFromAllocationPayload } from '../types';

export default function ProjectsPage() {
  const navigate = useNavigate();
  const { data: projects, isLoading } = useProjects();
  const { data: allocations } = useLandAllocations();
  const createFromAllocationMutation = useCreateProjectFromAllocation();
  const { hasRole } = useRole();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [formData, setFormData] = useState<CreateProjectFromAllocationPayload>({
    land_allocation_id: '',
    name: '',
  });

  const canCreate = hasRole(['tenant_admin', 'accountant']);

  const handleCreate = async () => {
    try {
      await createFromAllocationMutation.mutateAsync(formData);
      toast.success('Project created successfully');
      setShowCreateModal(false);
      setFormData({ land_allocation_id: '', name: '' });
    } catch (error: any) {
      toast.error(error.message || 'Failed to create project');
    }
  };

  const columns: Column<Project>[] = [
    { header: 'Name', accessor: 'name' },
    {
      header: 'Crop Cycle',
      accessor: (row) => row.crop_cycle?.name || 'N/A',
    },
    {
      header: 'HARI',
      accessor: (row) => row.party?.name || 'N/A',
    },
    { header: 'Status', accessor: 'status' },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex space-x-2">
          <Link
            to={`/app/projects/${row.id}`}
            className="text-blue-600 hover:text-blue-900"
          >
            View
          </Link>
          <Link
            to={`/app/projects/${row.id}/rules`}
            className="text-blue-600 hover:text-blue-900"
          >
            Rules
          </Link>
        </div>
      ),
    },
  ];

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  const availableAllocations = allocations?.filter((a) => !a.project) || [];

  return (
    <div>
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Projects</h1>
        {canCreate && (
          <button
            onClick={() => setShowCreateModal(true)}
            className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
          >
            New Project from Allocation
          </button>
        )}
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={projects || []}
          columns={columns}
          onRowClick={(row) => navigate(`/app/projects/${row.id}`)}
        />
      </div>

      <Modal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title="Create Project from Allocation"
      >
        <div className="space-y-4">
          <FormField label="Allocation" required>
            <select
              value={formData.land_allocation_id}
              onChange={(e) => setFormData({ ...formData, land_allocation_id: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">Select allocation</option>
              {availableAllocations.map((alloc) => (
                <option key={alloc.id} value={alloc.id}>
                  {alloc.land_parcel?.name} - {alloc.party?.name} ({alloc.allocated_acres} acres)
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Project Name" required>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
              onClick={() => setShowCreateModal(false)}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handleCreate}
              disabled={createFromAllocationMutation.isPending || !formData.land_allocation_id || !formData.name}
              className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createFromAllocationMutation.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
