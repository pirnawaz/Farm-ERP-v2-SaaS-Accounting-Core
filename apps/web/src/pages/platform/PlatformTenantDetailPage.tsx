import { useState, useCallback } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { usePlatformTenant, useUpdatePlatformTenant } from '../../hooks/usePlatformTenants';
import { useStartImpersonation } from '../../hooks/useImpersonation';
import { useTenant } from '../../hooks/useTenant';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useFormatting } from '../../hooks/useFormatting';
import { platformApi, type PlatformTenantModuleItem } from '../../api/platform';
import toast from 'react-hot-toast';
import type { UpdatePlatformTenantPayload } from '../../types';

export default function PlatformTenantDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { setTenantId } = useTenant();
  const { data: tenant, isLoading, error } = usePlatformTenant(id || null);
  const updateMutation = useUpdatePlatformTenant();
  const startImpersonation = useStartImpersonation();
  const { formatDate } = useFormatting();

  const [editOpen, setEditOpen] = useState(false);
  const [editForm, setEditForm] = useState<UpdatePlatformTenantPayload>({});
  const [resetPasswordOpen, setResetPasswordOpen] = useState(false);
  const [resetPasswordMode, setResetPasswordMode] = useState<'token' | 'direct'>('token');
  const [resetPasswordValue, setResetPasswordValue] = useState('');
  const [resetTokenResult, setResetTokenResult] = useState<string | null>(null);

  const { data: modulesData, isLoading: modulesLoading } = useQuery({
    queryKey: ['platformTenantModules', id],
    queryFn: () => platformApi.getTenantModules(id!),
    enabled: !!id,
  });

  const updateModulesMutation = useMutation({
    mutationFn: (updates: Array<{ key: string; enabled: boolean }>) =>
      platformApi.updateTenantModules(id!, { modules: updates }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platformTenantModules', id] });
      queryClient.invalidateQueries({ queryKey: ['platformTenants', id] });
      toast.success('Modules updated');
    },
    onError: (e: Error) => {
      toast.error(e?.message ?? 'Failed to update modules');
    },
  });

  const resetPasswordMutation = useMutation({
    mutationFn: (newPassword?: string) => platformApi.resetTenantAdminPassword(id!, newPassword),
    onSuccess: (data) => {
      if (data.reset_token) {
        setResetTokenResult(data.reset_token);
      } else {
        setResetPasswordOpen(false);
        setResetPasswordValue('');
        setResetTokenResult(null);
        toast.success(data.message);
      }
    },
    onError: (e: Error) => {
      toast.error(e?.message ?? 'Failed to reset password');
    },
  });

  const archiveMutation = useMutation({
    mutationFn: () => platformApi.archiveTenant(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platformTenants', id] });
      queryClient.invalidateQueries({ queryKey: ['platformTenants'] });
      toast.success('Tenant archived');
    },
    onError: (e: Error) => toast.error((e as Error)?.message ?? 'Failed to archive'),
  });

  const unarchiveMutation = useMutation({
    mutationFn: () => platformApi.unarchiveTenant(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platformTenants', id] });
      queryClient.invalidateQueries({ queryKey: ['platformTenants'] });
      toast.success('Tenant unarchived');
    },
    onError: (e: Error) => toast.error((e as Error)?.message ?? 'Failed to unarchive'),
  });

  const handleModuleToggle = useCallback(
    (mod: PlatformTenantModuleItem, nextEnabled: boolean) => {
      if (!modulesData?.modules) return;
      if (mod.is_core && !nextEnabled) return;
      if (!mod.allowed_by_plan && nextEnabled) return;
      const updates = modulesData.modules.map((m) => ({
        key: m.key,
        enabled: m.key === mod.key ? nextEnabled : m.enabled,
      }));
      updateModulesMutation.mutate(updates);
    },
    [modulesData, updateModulesMutation]
  );

  const handleUpdate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!id) return;
    try {
      await updateMutation.mutateAsync({ id, payload: editForm });
      toast.success('Tenant updated');
      setEditOpen(false);
      setEditForm({});
    } catch (e: unknown) {
      toast.error((e as Error)?.message || 'Failed to update');
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error || !tenant) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">Error: {(error as Error)?.message || 'Tenant not found'}</p>
        <Link to="/app/platform/tenants" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
          Back to tenants
        </Link>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6 flex justify-between items-center">
        <div>
          <Link to="/app/platform/tenants" className="text-[#1F6F5C] hover:underline text-sm mb-2 inline-block">
            ← Back to tenants
          </Link>
          <h1 className="text-2xl font-bold text-gray-900">{tenant.name}</h1>
          <p className="text-sm text-gray-500 mt-1">Tenant ID: {tenant.id}</p>
        </div>
        <div className="flex gap-2">
          <button
            type="button"
            onClick={async () => {
              try {
                await startImpersonation.mutateAsync({ tenantId: tenant.id });
                setTenantId(tenant.id);
                toast.success(`Impersonating ${tenant.name}`);
                navigate('/app/dashboard');
              } catch (e) {
                toast.error((e as Error)?.message || 'Failed to start impersonation');
              }
            }}
            disabled={startImpersonation.isPending}
            className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50"
            data-testid="impersonate-tenant-detail"
          >
            Impersonate
          </button>
          <button
            type="button"
            onClick={() => {
              setEditForm({ name: tenant.name, status: tenant.status, plan_key: tenant.plan_key ?? undefined });
              setEditOpen(true);
            }}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
          >
            Edit tenant
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Tenant Information */}
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Tenant Information</h2>
          <dl className="space-y-3">
            <div>
              <dt className="text-sm font-medium text-gray-500">Status</dt>
              <dd className="mt-1">
                <span
                  className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                    tenant.status === 'active'
                      ? 'bg-green-100 text-green-800'
                      : tenant.status === 'archived'
                        ? 'bg-gray-100 text-gray-800'
                        : 'bg-yellow-100 text-yellow-800'
                  }`}
                >
                  {tenant.status}
                </span>
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Plan</dt>
              <dd className="mt-1 text-sm text-gray-900">{tenant.plan_key || '—'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Currency</dt>
              <dd className="mt-1 text-sm text-gray-900">{tenant.currency_code}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Locale</dt>
              <dd className="mt-1 text-sm text-gray-900">{tenant.locale}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Timezone</dt>
              <dd className="mt-1 text-sm text-gray-900">{tenant.timezone}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Created</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {formatDate(tenant.created_at)}
              </dd>
            </div>
          </dl>
        </div>

        {/* Farm Profile */}
        {tenant.farm && (
          <div className="bg-white rounded-lg shadow p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Farm Profile</h2>
            <dl className="space-y-3">
              <div>
                <dt className="text-sm font-medium text-gray-500">Farm Name</dt>
                <dd className="mt-1 text-sm text-gray-900">{tenant.farm.farm_name}</dd>
              </div>
              {tenant.farm.country && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">Country</dt>
                  <dd className="mt-1 text-sm text-gray-900">{tenant.farm.country}</dd>
                </div>
              )}
              {tenant.farm.city && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">City</dt>
                  <dd className="mt-1 text-sm text-gray-900">{tenant.farm.city}</dd>
                </div>
              )}
              {tenant.farm.phone && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">Phone</dt>
                  <dd className="mt-1 text-sm text-gray-900">{tenant.farm.phone}</dd>
                </div>
              )}
            </dl>
          </div>
        )}

        {/* Support actions */}
        <div className="bg-white rounded-lg shadow p-6 md:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Support actions</h2>
          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={() => {
                setResetTokenResult(null);
                setResetPasswordValue('');
                setResetPasswordOpen(true);
              }}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm"
            >
              Reset admin password
            </button>
            {tenant.status === 'archived' ? (
              <button
                type="button"
                onClick={() => unarchiveMutation.mutate()}
                disabled={unarchiveMutation.isPending}
                className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 text-sm"
              >
                {unarchiveMutation.isPending ? 'Unarchiving...' : 'Unarchive tenant'}
              </button>
            ) : (
              <button
                type="button"
                onClick={() => {
                  if (window.confirm(`Archive tenant "${tenant.name}"? They will not be able to sign in until unarchived.`)) {
                    archiveMutation.mutate();
                  }
                }}
                disabled={archiveMutation.isPending}
                className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50 text-sm"
              >
                {archiveMutation.isPending ? 'Archiving...' : 'Archive tenant'}
              </button>
            )}
          </div>
        </div>

        {/* Modules */}
        <div className="bg-white rounded-lg shadow p-6 md:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900 mb-2">Modules</h2>
          <p className="text-sm text-gray-500 mb-4">
            Plan: <span className="font-medium text-gray-700">{tenant.plan_key || '—'}</span>
            {' · '}
            Edit plan in &quot;Edit tenant&quot; to change which modules can be enabled.
          </p>
          {modulesLoading ? (
            <div className="flex justify-center py-4">
              <LoadingSpinner />
            </div>
          ) : modulesData?.modules ? (
            <ul className="space-y-3">
              {modulesData.modules.map((mod) => (
                <li
                  key={mod.key}
                  className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0"
                >
                  <div className="flex-1 min-w-0">
                    <span className="font-medium text-gray-900">{mod.name}</span>
                    {mod.is_core && (
                      <span className="ml-2 text-xs text-amber-600">core</span>
                    )}
                    {!mod.allowed_by_plan && !mod.is_core && (
                      <span className="ml-2 text-xs text-gray-500" title={`Not allowed on plan "${modulesData.plan_key ?? 'null'}"`}>
                        (not on plan)
                      </span>
                    )}
                  </div>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <span className="text-sm text-gray-600">
                      {mod.enabled ? 'On' : 'Off'}
                    </span>
                    <input
                      type="checkbox"
                      checked={mod.enabled}
                      disabled={
                        mod.is_core ||
                        (!mod.allowed_by_plan && !mod.enabled) ||
                        updateModulesMutation.isPending
                      }
                      title={
                        mod.is_core
                          ? 'Core modules cannot be disabled'
                          : !mod.allowed_by_plan
                            ? `Module ${mod.key} is not allowed on plan ${modulesData.plan_key ?? 'null'}`
                            : undefined
                      }
                      onChange={(e) => handleModuleToggle(mod, e.target.checked)}
                      className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C] disabled:opacity-50"
                    />
                  </label>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-gray-500">No modules data.</p>
          )}
        </div>
      </div>

      {/* Reset admin password modal */}
      <Modal
        isOpen={resetPasswordOpen}
        onClose={() => {
          setResetPasswordOpen(false);
          setResetPasswordValue('');
          setResetTokenResult(null);
        }}
        title="Reset tenant admin password"
        size="md"
      >
        {resetTokenResult ? (
          <div className="space-y-3">
            <p className="text-sm text-gray-600">
              One-time token (provide to tenant admin to set a new password; expires in 24 hours):
            </p>
            <pre className="p-3 bg-gray-100 rounded text-sm break-all font-mono">{resetTokenResult}</pre>
            <button
              type="button"
              onClick={() => {
                setResetPasswordOpen(false);
                setResetTokenResult(null);
                toast.success('Copy the token and share it securely.');
              }}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md"
            >
              Done
            </button>
          </div>
        ) : (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              if (resetPasswordMode === 'direct' && resetPasswordValue.trim()) {
                resetPasswordMutation.mutate(resetPasswordValue.trim());
              } else if (resetPasswordMode === 'token') {
                resetPasswordMutation.mutate(undefined);
              }
            }}
            className="space-y-4"
          >
            <div className="flex gap-4">
              <label className="flex items-center gap-2">
                <input
                  type="radio"
                  name="resetMode"
                  checked={resetPasswordMode === 'token'}
                  onChange={() => setResetPasswordMode('token')}
                />
                <span className="text-sm">Generate token (share with admin)</span>
              </label>
              <label className="flex items-center gap-2">
                <input
                  type="radio"
                  name="resetMode"
                  checked={resetPasswordMode === 'direct'}
                  onChange={() => setResetPasswordMode('direct')}
                />
                <span className="text-sm">Set new password directly</span>
              </label>
            </div>
            {resetPasswordMode === 'direct' && (
              <FormField label="New password">
                <input
                  type="password"
                  value={resetPasswordValue}
                  onChange={(e) => setResetPasswordValue(e.target.value)}
                  placeholder="Min 8 characters"
                  className="w-full border border-gray-300 rounded-md px-3 py-2"
                  minLength={8}
                />
              </FormField>
            )}
            <div className="flex justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => {
                  setResetPasswordOpen(false);
                  setResetPasswordValue('');
                }}
                className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={
                  resetPasswordMutation.isPending ||
                  (resetPasswordMode === 'direct' && !resetPasswordValue.trim())
                }
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {resetPasswordMutation.isPending ? 'Processing...' : resetPasswordMode === 'token' ? 'Generate token' : 'Set password'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      {/* Edit Modal */}
      <Modal isOpen={editOpen} onClose={() => { setEditOpen(false); setEditForm({}); }} title="Edit tenant" size="md">
        <form onSubmit={handleUpdate} className="space-y-4">
          <FormField label="Name">
            <input
              type="text"
              value={editForm.name ?? tenant.name}
              onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            />
          </FormField>
          <FormField label="Status">
            <select
              value={editForm.status ?? tenant.status}
              onChange={(e) => setEditForm({ ...editForm, status: e.target.value as 'active' | 'suspended' | 'archived' })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            >
              <option value="active">active</option>
              <option value="suspended">suspended</option>
              <option value="archived">archived</option>
            </select>
          </FormField>
          <FormField label="Plan">
            <select
              value={editForm.plan_key ?? tenant.plan_key ?? ''}
              onChange={(e) => setEditForm({ ...editForm, plan_key: e.target.value || undefined })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            >
              <option value="">—</option>
              <option value="starter">Starter</option>
              <option value="growth">Growth</option>
              <option value="enterprise">Enterprise</option>
            </select>
          </FormField>
          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={() => { setEditOpen(false); setEditForm({}); }}
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
