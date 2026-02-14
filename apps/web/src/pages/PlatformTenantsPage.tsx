import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { DataTable } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { LoadingSpinner } from '../components/LoadingSpinner';
import {
  usePlatformTenants,
  useCreatePlatformTenant,
  useUpdatePlatformTenant,
} from '../hooks/usePlatformTenants';
import { useStartImpersonation } from '../hooks/useImpersonation';
import { useTenant } from '../hooks/useTenant';
import type { CreatePlatformTenantPayload, UpdatePlatformTenantPayload } from '../types';
import toast from 'react-hot-toast';

const PLAN_OPTIONS = [{ value: '', label: '—' }, { value: 'starter', label: 'Starter' }, { value: 'growth', label: 'Growth' }, { value: 'enterprise', label: 'Enterprise' }];

export default function PlatformTenantsPage() {
  const navigate = useNavigate();
  const { setTenantId } = useTenant();
  const { data, isLoading, error } = usePlatformTenants();
  const createMutation = useCreatePlatformTenant();
  const updateMutation = useUpdatePlatformTenant();
  const startImpersonation = useStartImpersonation();

  const [createOpen, setCreateOpen] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<UpdatePlatformTenantPayload>({});
  const [createForm, setCreateForm] = useState<CreatePlatformTenantPayload>({
    name: '',
    country: '',
    initial_admin_email: '',
    initial_admin_password: '',
    initial_admin_name: '',
  });

  const tenants = data?.tenants ?? [];

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await createMutation.mutateAsync(createForm);
      toast.success('Tenant created');
      setCreateOpen(false);
      setCreateForm({
        name: '',
        country: '',
        initial_admin_email: '',
        initial_admin_password: '',
        initial_admin_name: '',
      });
    } catch (e: unknown) {
      toast.error((e as Error)?.message || 'Failed to create tenant');
    }
  };

  const handleUpdate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editId) return;
    try {
      await updateMutation.mutateAsync({ id: editId, payload: editForm });
      toast.success('Tenant updated');
      setEditId(null);
      setEditForm({});
    } catch (e: unknown) {
      toast.error((e as Error)?.message || 'Failed to update');
    }
  };

  const handleImpersonate = async (tenantId: string, tenantName: string) => {
    try {
      await startImpersonation.mutateAsync({ tenantId });
      setTenantId(tenantId);
      toast.success(`Impersonating ${tenantName}`);
      navigate('/app/dashboard');
    } catch (e) {
      toast.error((e as Error)?.message || 'Failed to start impersonation');
    }
  };

  const handleQuickStatus = async (row: (typeof tenants)[0], newStatus: 'active' | 'suspended') => {
    try {
      await updateMutation.mutateAsync({ id: row.id, payload: { status: newStatus } });
      toast.success(`Tenant ${newStatus}`);
    } catch (e) {
      toast.error((e as Error)?.message || 'Failed to update');
    }
  };

  const columns = [
    { header: 'Name', accessor: 'name' as const },
    {
      header: 'Status',
      accessor: (row: (typeof tenants)[0]) => (
        <span
          className={`px-2 py-1 text-xs font-semibold rounded-full ${
            row.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'
          }`}
          data-testid={`tenant-status-${row.id}`}
        >
          {row.status}
        </span>
      ),
    },
    { header: 'Plan', accessor: (row: (typeof tenants)[0]) => row.plan_key || '—' as const },
    { header: 'Currency', accessor: 'currency_code' as const },
    { header: 'Locale', accessor: 'locale' as const },
    { header: 'Timezone', accessor: 'timezone' as const },
    {
      header: 'Actions',
      accessor: (row: (typeof tenants)[0]) => (
        <div className="flex flex-wrap gap-2 items-center">
          <Link
            to={`/app/platform/tenants/${row.id}`}
            className="text-[#1F6F5C] hover:underline text-sm"
          >
            View
          </Link>
          {row.status === 'active' ? (
            <button
              type="button"
              onClick={() => handleQuickStatus(row, 'suspended')}
              className="text-amber-600 hover:underline text-sm"
            >
              Suspend
            </button>
          ) : (
            <button
              type="button"
              onClick={() => handleQuickStatus(row, 'active')}
              className="text-green-600 hover:underline text-sm"
            >
              Activate
            </button>
          )}
          <button
            type="button"
            onClick={() => {
              setEditId(row.id);
              setEditForm({ name: row.name, status: row.status, plan_key: row.plan_key ?? undefined });
            }}
            className="text-[#1F6F5C] hover:underline text-sm"
          >
            Edit
          </button>
          <button
            type="button"
            onClick={() => handleImpersonate(row.id, row.name)}
            className="text-[#1F6F5C] hover:underline text-sm"
            data-testid={`impersonate-tenant-${row.id}`}
          >
            Impersonate
          </button>
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

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">Error: {(error as Error).message}</p>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6 flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Tenants</h1>
          <p className="text-sm text-gray-500 mt-1">Create and manage tenants. Each gets a farm profile, system accounts, and an initial admin user.</p>
        </div>
        <button
          type="button"
          onClick={() => setCreateOpen(true)}
          className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
        >
          Create tenant
        </button>
      </div>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        <DataTable data={tenants} columns={columns} emptyMessage="No tenants." />
      </div>

      <Modal isOpen={createOpen} onClose={() => setCreateOpen(false)} title="Create tenant" size="lg">
        <form onSubmit={handleCreate} className="space-y-4">
          <FormField label="Tenant name" required>
            <input
              type="text"
              value={createForm.name}
              onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              required
              minLength={2}
            />
          </FormField>
          <FormField label="Country (optional, for defaults)">
            <input
              type="text"
              value={createForm.country ?? ''}
              onChange={(e) => setCreateForm({ ...createForm, country: e.target.value || undefined })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              placeholder="e.g. PK, GB"
            />
          </FormField>
          <hr className="my-4" />
          <h4 className="font-medium text-gray-900">Initial tenant admin</h4>
          <FormField label="Name" required>
            <input
              type="text"
              value={createForm.initial_admin_name}
              onChange={(e) => setCreateForm({ ...createForm, initial_admin_name: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              required
            />
          </FormField>
          <FormField label="Email" required>
            <input
              type="email"
              value={createForm.initial_admin_email}
              onChange={(e) => setCreateForm({ ...createForm, initial_admin_email: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              required
            />
          </FormField>
          <FormField label="Password" required>
            <input
              type="password"
              value={createForm.initial_admin_password}
              onChange={(e) => setCreateForm({ ...createForm, initial_admin_password: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              required
              minLength={8}
            />
          </FormField>
          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={() => setCreateOpen(false)}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={createMutation.isPending}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {createMutation.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </form>
      </Modal>

      <Modal isOpen={!!editId} onClose={() => { setEditId(null); setEditForm({}); }} title="Edit tenant" size="md">
        <form onSubmit={handleUpdate} className="space-y-4">
          <FormField label="Name">
            <input
              type="text"
              value={editForm.name ?? ''}
              onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            />
          </FormField>
          <FormField label="Status">
            <select
              value={editForm.status ?? 'active'}
              onChange={(e) => setEditForm({ ...editForm, status: e.target.value as 'active' | 'suspended' })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            >
              <option value="active">active</option>
              <option value="suspended">suspended</option>
            </select>
          </FormField>
          <FormField label="Plan">
            <select
              value={editForm.plan_key ?? ''}
              onChange={(e) => setEditForm({ ...editForm, plan_key: e.target.value || undefined })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            >
              {PLAN_OPTIONS.map((o) => (
                <option key={o.value || 'none'} value={o.value}>{o.label}</option>
              ))}
            </select>
          </FormField>
          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={() => { setEditId(null); setEditForm({}); }}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={updateMutation.isPending}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {updateMutation.isPending ? 'Saving...' : 'Save'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
