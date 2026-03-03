import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Modal } from '../components/Modal';
import { useQueryClient } from '@tanstack/react-query';
import { useProjects, useCreateProjectFromAllocation, useCloseProject, useReopenProject } from '../hooks/useProjects';
import { useLandAllocations } from '../hooks/useLandAllocations';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import { EmptyState } from '../components/EmptyState';
import toast from 'react-hot-toast';
import type { Project, CreateProjectFromAllocationPayload } from '../types';

export default function ProjectsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { data: projects, isLoading } = useProjects();
  const { data: allocations } = useLandAllocations();
  const createFromAllocationMutation = useCreateProjectFromAllocation();
  const closeProjectMutation = useCloseProject();
  const reopenProjectMutation = useReopenProject();
  const { hasRole } = useRole();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [projectToClose, setProjectToClose] = useState<Project | null>(null);
  const [formData, setFormData] = useState<CreateProjectFromAllocationPayload>({
    land_allocation_id: '',
    name: '',
  });

  const canCreate = hasRole(['tenant_admin', 'accountant']);

  const handleCreate = async () => {
    try {
      const isFirstProject = !projects || projects.length === 0;
      const created = await createFromAllocationMutation.mutateAsync(formData);
      queryClient.setQueryData<Project[]>(['projects', undefined], (old) => {
        if (!old) return [created];
        return [...old, created].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
      });
      await queryClient.refetchQueries({ queryKey: ['projects', undefined] });
      if (isFirstProject) {
        toast.success('Your first project has been created. You can now track costs and activities.');
      } else {
        toast.success('Project created successfully');
      }
      setShowCreateModal(false);
      setFormData({ land_allocation_id: '', name: '' });
    } catch (error: any) {
      toast.error(error.message || 'Failed to create project');
    }
  };

  const handleCloseConfirm = async () => {
    if (!projectToClose) return;
    try {
      await closeProjectMutation.mutateAsync(projectToClose.id);
      toast.success('Project closed');
      setProjectToClose(null);
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? 'Failed to close project');
    }
  };

  const handleReopen = async (project: Project) => {
    try {
      await reopenProjectMutation.mutateAsync(project.id);
      toast.success('Project reopened');
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? 'Failed to reopen project');
    }
  };

  const columns: Column<Project>[] = [
    {
      header: 'Name',
      accessor: (row) => (
        <span className={row.status === 'CLOSED' ? 'text-gray-500' : ''}>
          {row.name}
          {row.status === 'CLOSED' && (
            <span className="ml-2 text-xs font-medium text-gray-400">(Closed)</span>
          )}
        </span>
      ),
    },
    {
      header: 'Crop Cycle',
      accessor: (row) => row.crop_cycle?.name || 'N/A',
    },
    {
      header: 'HARI',
      accessor: (row) => row.party?.name || 'N/A',
    },
    {
      header: 'Status',
      accessor: (row) => (
        <span className={row.status === 'CLOSED' ? 'text-gray-500' : ''}>{row.status}</span>
      ),
    },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex flex-wrap items-center gap-2" onClick={(e) => e.stopPropagation()}>
          <Link
            to={`/app/projects/${row.id}`}
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View
          </Link>
          <Link
            to={`/app/projects/${row.id}/rules`}
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            Rules
          </Link>
          {canCreate && row.status === 'ACTIVE' && (
            <button
              type="button"
              onClick={() => setProjectToClose(row)}
              className="text-amber-600 hover:text-amber-700"
            >
              Close
            </button>
          )}
          {canCreate && row.status === 'CLOSED' && (
            <button
              type="button"
              onClick={() => handleReopen(row)}
              className="text-[#1F6F5C] hover:text-[#1a5a4a]"
            >
              Reopen
            </button>
          )}
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
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Project from Allocation
          </button>
        )}
      </div>

      <div className="bg-white rounded-lg shadow">
        {projects && projects.length > 0 ? (
          <DataTable
            data={projects}
            columns={columns}
            onRowClick={(row) => navigate(`/app/projects/${row.id}`)}
          />
        ) : (
          <EmptyState
            title="No projects yet"
            description="Projects help track crops, fields, and costs."
            action={
              canCreate
                ? {
                    label: 'Create Project',
                    onClick: () => setShowCreateModal(true),
                  }
                : undefined
            }
          />
        )}
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
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select allocation</option>
              {availableAllocations.map((alloc) => (
                <option key={alloc.id} value={alloc.id}>
                  {alloc.land_parcel?.name} - {alloc.party?.name || 'Owner-operated'} ({alloc.allocated_acres} acres)
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Project Name" required>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
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
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createFromAllocationMutation.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={!!projectToClose}
        onClose={() => setProjectToClose(null)}
        title="Close Project"
      >
        <p className="text-gray-600 mb-4">
          Closing prevents new work/harvest entries and rule changes.
        </p>
        <p className="text-sm text-gray-500 mb-4">
          Are you sure you want to close <strong>{projectToClose?.name}</strong>?
        </p>
        <div className="flex justify-end space-x-3">
          <button
            onClick={() => setProjectToClose(null)}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            onClick={handleCloseConfirm}
            disabled={closeProjectMutation.isPending}
            className="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-md hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {closeProjectMutation.isPending ? 'Closing...' : 'Close Project'}
          </button>
        </div>
      </Modal>
    </div>
  );
}
