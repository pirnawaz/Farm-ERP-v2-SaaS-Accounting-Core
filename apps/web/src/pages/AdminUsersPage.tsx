import { useState } from 'react';
import { DataTable } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { LoadingSpinner } from '../components/LoadingSpinner';
import {
  useTenantUsers,
  useCreateTenantUser,
  useUpdateTenantUser,
  useDisableTenantUser,
} from '../hooks/useTenantUsers';
import type { User, UserRole } from '../types';
import toast from 'react-hot-toast';

const TENANT_ROLES: UserRole[] = ['tenant_admin', 'accountant', 'operator'];

export default function AdminUsersPage() {
  const { data: users = [], isLoading, error } = useTenantUsers();
  const createMutation = useCreateTenantUser();
  const updateMutation = useUpdateTenantUser();
  const disableMutation = useDisableTenantUser();

  const [createOpen, setCreateOpen] = useState(false);
  const [disableTarget, setDisableTarget] = useState<User | null>(null);
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'operator' as UserRole });

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await createMutation.mutateAsync({
        name: form.name,
        email: form.email,
        password: form.password,
        role: form.role as UserRole,
      });
      toast.success('User created');
      setCreateOpen(false);
      setForm({ name: '', email: '', password: '', role: 'operator' });
    } catch (e: unknown) {
      const err = e as Error & { message?: string };
      toast.error(err?.message || 'Failed to create user');
    }
  };

  const handleToggleEnabled = async (u: User) => {
    try {
      await updateMutation.mutateAsync({
        id: u.id,
        payload: { is_enabled: !u.is_enabled },
      });
      toast.success(u.is_enabled ? 'User disabled' : 'User enabled');
    } catch (e: unknown) {
      const err = e as Error & { message?: string };
      toast.error(err?.message || 'Failed to update');
    }
  };

  const handleRoleChange = async (u: User, role: UserRole) => {
    if (u.role === role) return;
    try {
      await updateMutation.mutateAsync({ id: u.id, payload: { role } });
      toast.success('Role updated');
    } catch (e: unknown) {
      const err = e as Error & { message?: string };
      toast.error(err?.message || 'Failed to update');
    }
  };

  const handleDisableConfirm = async () => {
    if (!disableTarget) return;
    try {
      await disableMutation.mutateAsync(disableTarget.id);
      toast.success('User disabled');
      setDisableTarget(null);
    } catch (e: unknown) {
      const err = e as Error & { message?: string };
      toast.error(err?.message || 'Failed to disable');
      setDisableTarget(null);
    }
  };

  const columns = [
    { header: 'Name', accessor: 'name' as const },
    { header: 'Email', accessor: 'email' as const },
    {
      header: 'Role',
      accessor: (row: User) => (
        <select
          value={row.role}
          onChange={(e) => handleRoleChange(row, e.target.value as UserRole)}
          className="border border-gray-300 rounded px-2 py-1 text-sm"
        >
          {TENANT_ROLES.map((r) => (
            <option key={r} value={r}>
              {r}
            </option>
          ))}
        </select>
      ),
    },
    {
      header: 'Status',
      accessor: (row: User) => (
        <span className={`px-2 py-1 text-xs rounded ${
          row.is_enabled ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
        }`}>
          {row.is_enabled ? 'Enabled' : 'Disabled'}
        </span>
      ),
    },
    {
      header: 'Created',
      accessor: (row: User) => (
        <span className="text-sm text-gray-600">
          {row.created_at ? new Date(row.created_at).toLocaleDateString() : '-'}
        </span>
      ),
    },
    {
      header: 'Actions',
      accessor: (row: User) => (
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => handleToggleEnabled(row)}
            className={`text-sm px-2 py-1 rounded ${
              row.is_enabled 
                ? 'text-orange-600 hover:bg-orange-50' 
                : 'text-green-600 hover:bg-green-50'
            }`}
          >
            {row.is_enabled ? 'Disable' : 'Enable'}
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
        <p className="text-red-800">Error loading users: {(error as Error).message}</p>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6 flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Users</h1>
          <p className="text-sm text-gray-500 mt-1">Manage tenant users, roles, and enable/disable.</p>
        </div>
        <button
          type="button"
          onClick={() => setCreateOpen(true)}
          className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
        >
          Add user
        </button>
      </div>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        <DataTable data={users} columns={columns} emptyMessage="No users yet." />
      </div>

      <Modal isOpen={createOpen} onClose={() => setCreateOpen(false)} title="Create user" size="md">
        <form onSubmit={handleCreate} className="space-y-4">
          <FormField label="Name" required>
            <input
              type="text"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              required
            />
          </FormField>
          <FormField label="Email" required>
            <input
              type="email"
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              required
            />
          </FormField>
          <FormField label="Password" required>
            <input
              type="password"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              required
              minLength={8}
            />
          </FormField>
          <FormField label="Role" required>
            <select
              value={form.role}
              onChange={(e) => setForm({ ...form, role: e.target.value as UserRole })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            >
              {TENANT_ROLES.map((r) => (
                <option key={r} value={r}>
                  {r}
                </option>
              ))}
            </select>
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
              className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {createMutation.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </form>
      </Modal>

      <ConfirmDialog
        isOpen={!!disableTarget}
        onClose={() => setDisableTarget(null)}
        onConfirm={handleDisableConfirm}
        title="Disable user"
        message={disableTarget ? `Disable ${disableTarget.name} (${disableTarget.email})? They will not be able to sign in.` : ''}
        confirmText="Disable"
        variant="danger"
      />
    </div>
  );
}
