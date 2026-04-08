import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Modal } from '../components/Modal';
import { useProjects, useCreateProjectFromAllocation, useCloseProject, useReopenProject } from '../hooks/useProjects';
import { useLandAllocations } from '../hooks/useLandAllocations';
import { useCropCycles } from '../hooks/useCropCycles';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import { Badge } from '../components/Badge';
import toast from 'react-hot-toast';
import type { Project, CreateProjectFromAllocationPayload } from '../types';
import { term } from '../config/terminology';

export default function ProjectsPage() {
  const navigate = useNavigate();
  const [selectedCropCycleId, setSelectedCropCycleId] = useState('');
  const { data: projects, isLoading } = useProjects(selectedCropCycleId || undefined);
  const { data: cropCycles } = useCropCycles();
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
      const isFirstProjectEver =
        !selectedCropCycleId && (projects?.length ?? 0) === 0;
      await createFromAllocationMutation.mutateAsync(formData);
      if (isFirstProjectEver) {
        toast.success(`Your first ${term('fieldCycle').toLowerCase()} has been created. You can now track costs and activities.`);
      } else {
        toast.success(`${term('fieldCycle')} created successfully`);
      }
      setShowCreateModal(false);
      setFormData({ land_allocation_id: '', name: '' });
    } catch (error: any) {
      toast.error(error.message || `Failed to create ${term('fieldCycle').toLowerCase()}`);
    }
  };

  const handleCloseConfirm = async () => {
    if (!projectToClose) return;
    try {
      await closeProjectMutation.mutateAsync(projectToClose.id);
      toast.success(`${term('fieldCycle')} closed`);
      setProjectToClose(null);
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? `Failed to close ${term('fieldCycle').toLowerCase()}`);
    }
  };

  const handleReopen = async (project: Project) => {
    try {
      await reopenProjectMutation.mutateAsync(project.id);
      toast.success(`${term('fieldCycle')} reopened`);
    } catch (error: any) {
      toast.error(error?.response?.data?.message ?? error.message ?? `Failed to reopen ${term('fieldCycle').toLowerCase()}`);
    }
  };

  const columns: Column<Project>[] = [
    {
      header: term('fieldCycle'),
      accessor: (row) => (
        <Link to={`/app/projects/${row.id}`} className="font-semibold text-[#1F6F5C] hover:text-[#1a5a4a]">
          {row.name}
        </Link>
      ),
    },
    {
      header: 'Crop cycle',
      accessor: (row) => <span className="text-gray-800">{row.crop_cycle?.name || '—'}</span>,
    },
    {
      header: 'Assignee',
      accessor: (row) => <span className="text-gray-800">{row.party?.name || '—'}</span>,
    },
    {
      header: 'Status',
      accessor: (row) => (
        <Badge variant={row.status === 'ACTIVE' ? 'success' : 'neutral'} size="md">
          {row.status === 'ACTIVE' ? 'Active' : 'Closed'}
        </Badge>
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

  const projectRows = projects ?? [];
  const hasFilter = selectedCropCycleId.length > 0;
  const selectedCycleName = useMemo(
    () => cropCycles?.find((c) => c.id === selectedCropCycleId)?.name,
    [cropCycles, selectedCropCycleId],
  );
  const summaryLine = useMemo(() => {
    const n = projectRows.length;
    const base = `${n} ${term('fieldCycles').toLowerCase()}`;
    if (hasFilter && selectedCycleName) return `${base} · Crop cycle: ${selectedCycleName}`;
    return base;
  }, [projectRows.length, hasFilter, selectedCycleName]);

  const clearFilters = () => setSelectedCropCycleId('');

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  const availableAllocations = allocations?.filter((a) => !a.project) || [];

  return (
    <div data-testid="field-cycles-page" className="space-y-6 max-w-7xl">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{term('fieldCycles')}</h1>
          <p className="mt-1 text-base text-gray-700">Plan and track field-level work within crop cycles.</p>
          <p className="mt-1 text-sm text-gray-500 max-w-2xl">
            Field cycles connect crop planning to specific fields and operational work.
          </p>
        </div>
        {canCreate && (
          <button
            type="button"
            onClick={() => setShowCreateModal(true)}
            className="shrink-0 px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            Create {term('fieldCycle')} from allocation
          </button>
        )}
      </div>

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasFilter}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="max-w-md">
          <label htmlFor="field-cycles-crop-cycle" className="block text-xs font-medium text-gray-600 mb-1">
            Crop cycle
          </label>
          <select
            id="field-cycles-crop-cycle"
            value={selectedCropCycleId}
            onChange={(e) => setSelectedCropCycleId(e.target.value)}
            className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          >
            <option value="">All crop cycles</option>
            {cropCycles?.map((cycle) => (
              <option key={cycle.id} value={cycle.id}>
                {cycle.name}
              </option>
            ))}
          </select>
          <p className="mt-1.5 text-xs text-gray-500">Focus the list on one season, or show all seasons.</p>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {projectRows.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">
            {hasFilter ? `No ${term('fieldCycles').toLowerCase()} for this crop cycle.` : `No ${term('fieldCycles').toLowerCase()} yet.`}
          </h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            {hasFilter
              ? 'Try another crop cycle or clear filters to see all field cycles.'
              : 'Create one to plan field-level work.'}
          </p>
          {hasFilter ? (
            <button
              type="button"
              onClick={clearFilters}
              className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
            >
              Clear filters
            </button>
          ) : canCreate ? (
            <button
              type="button"
              onClick={() => setShowCreateModal(true)}
              className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              Create {term('fieldCycle')} from allocation
            </button>
          ) : null}
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable
            data={projectRows}
            columns={columns}
            onRowClick={(row) => navigate(`/app/projects/${row.id}`)}
            emptyMessage=""
          />
        </div>
      )}

      <Modal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title={`Create ${term('fieldCycle')} from allocation`}
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
          <FormField label={`${term('fieldCycle')} Name`} required>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
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
        title={`Close ${term('fieldCycle')}`}
      >
        <p className="text-gray-600 mb-4">
          Closing prevents new work/harvest entries and rule changes.
        </p>
        <p className="text-sm text-gray-500 mb-4">
          Are you sure you want to close <strong>{projectToClose?.name}</strong>?
        </p>
        <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>button]:w-full sm:[&>button]:w-auto">
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
            {closeProjectMutation.isPending ? 'Closing...' : `Close ${term('fieldCycle')}`}
          </button>
        </div>
      </Modal>
    </div>
  );
}
