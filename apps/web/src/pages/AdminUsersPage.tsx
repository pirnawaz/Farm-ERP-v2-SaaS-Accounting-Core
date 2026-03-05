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
  useInviteUser,
} from '../hooks/useTenantUsers';
import { useFormatting } from '../hooks/useFormatting';
import type { User, UserRole } from '../types';
import toast from 'react-hot-toast';

const TENANT_ROLES: UserRole[] = ['tenant_admin', 'accountant', 'operator'];

export default function AdminUsersPage() {
  const { data: users = [], isLoading, error } = useTenantUsers();
  const createMutation = useCreateTenantUser();
  const updateMutation = useUpdateTenantUser();
  const disableMutation = useDisableTenantUser();
  const inviteMutation = useInviteUser();
  const { formatDate } = useFormatting();

  const [createOpen, setCreateOpen] = useState(false);
  const [createResult, setCreateResult] = useState<{ user: { name: string; email: string }; temporary_password: string } | null>(null);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteLink, setInviteLink] = useState<string | null>(null);
  const [disableTarget, setDisableTarget] = useState<User | null>(null);
  const [form, setForm] = useState({ name: '', email: '', role: 'operator' as UserRole });
  const [inviteForm, setInviteForm] = useState({ email: '', role: 'operator' as UserRole });

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const res = await createMutation.mutateAsync({
        name: form.name,
        email: form.email,
        role: form.role as UserRole,
      });
      setCreateResult({ user: res.user, temporary_password: res.temporary_password });
      toast.success('User created. Share the temporary password — it won’t be shown again.');
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

  const handleInvite = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const res = await inviteMutation.mutateAsync({ email: inviteForm.email, role: inviteForm.role });
      setInviteLink(res.invite_link);
      toast.success('Invitation created. Copy the link to share.');
    } catch (e: unknown) {
      const err = e as Error & { message?: string };
      toast.error(err?.message || 'Failed to create invitation');
    }
  };

  const copyInviteLink = () => {
    if (inviteLink) {
      navigator.clipboard.writeText(inviteLink);
      toast.success('Link copied to clipboard');
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
          {row.created_at ? formatDate(row.created_at) : '-'}
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
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => { setInviteOpen(true); setInviteLink(null); }}
            className="px-4 py-2 border border-[#1F6F5C] text-[#1F6F5C] rounded-md hover:bg-[#E6ECEA]"
          >
            Invite user
          </button>
          <button
            type="button"
            onClick={() => { setCreateOpen(true); setCreateResult(null); }}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
          >
            Create user
          </button>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        <DataTable data={users} columns={columns} emptyMessage="No users yet." />
      </div>

      <Modal isOpen={createOpen} onClose={() => { setCreateOpen(false); setCreateResult(null); setForm({ name: '', email: '', role: 'operator' }); }} title="Create user" size="md">
        {createResult ? (
          <div className="space-y-4">
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded p-3">
              This password is shown only once. Share it securely with {createResult.user.name}. They must change it on first login.
            </p>
            <FormField label="Temporary password">
              <div className="flex gap-2">
                <input
                  type="text"
                  readOnly
                  value={createResult.temporary_password}
                  className="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm font-mono bg-gray-50"
                />
                <button
                  type="button"
                  onClick={() => {
                    navigator.clipboard.writeText(createResult.temporary_password);
                    toast.success('Copied');
                  }}
                  className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
                >
                  Copy
                </button>
              </div>
            </FormField>
            <div className="flex justify-end">
              <button
                type="button"
                onClick={() => { setCreateOpen(false); setCreateResult(null); setForm({ name: '', email: '', role: 'operator' }); }}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
              >
                Done
              </button>
            </div>
          </div>
        ) : (
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
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {createMutation.isPending ? 'Creating...' : 'Create'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      <Modal isOpen={inviteOpen} onClose={() => { setInviteOpen(false); setInviteLink(null); }} title="Invite user" size="md">
        {!inviteLink ? (
          <form onSubmit={handleInvite} className="space-y-4">
            <FormField label="Email" required>
              <input
                type="email"
                value={inviteForm.email}
                onChange={(e) => setInviteForm({ ...inviteForm, email: e.target.value })}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
                required
              />
            </FormField>
            <FormField label="Role" required>
              <select
                value={inviteForm.role}
                onChange={(e) => setInviteForm({ ...inviteForm, role: e.target.value as UserRole })}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
              >
                {TENANT_ROLES.map((r) => (
                  <option key={r} value={r}>{r}</option>
                ))}
              </select>
            </FormField>
            <div className="flex justify-end gap-2 pt-2">
              <button type="button" onClick={() => { setInviteOpen(false); setInviteLink(null); }} className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
              <button type="submit" disabled={inviteMutation.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50">
                {inviteMutation.isPending ? 'Creating...' : 'Create invitation'}
              </button>
            </div>
          </form>
        ) : (
          <div className="space-y-4">
            <p className="text-sm text-gray-600">Share this link with the user. It expires in 7 days.</p>
            <div className="flex gap-2">
              <input type="text" readOnly value={inviteLink} className="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm bg-gray-50" />
              <button type="button" onClick={copyInviteLink} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">Copy</button>
            </div>
            <div className="flex justify-end">
              <button type="button" onClick={() => { setInviteOpen(false); setInviteLink(null); }} className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">Done</button>
            </div>
          </div>
        )}
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
