import { useState, useCallback, useMemo } from 'react';
import { useParams, Link, useNavigate, useLocation } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { usePlatformTenant, useUpdatePlatformTenant } from '../../hooks/usePlatformTenants';
import { useTenant } from '../../hooks/useTenant';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useFormatting } from '../../hooks/useFormatting';
import { platformApi, type PlatformTenantModuleItem } from '../../api/platform';
import { useImpersonation, IMPERSONATION_STATUS_UI_QUERY_KEY } from '../../hooks/useImpersonation';
import { getApiErrorMessage } from '../../utils/api';
import toast from 'react-hot-toast';
import type { UpdatePlatformTenantPayload } from '../../types';

const IMPERSONATION_RETURN_TO_KEY = 'impersonation.return_to';

export default function PlatformTenantDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { setTenantId } = useTenant();
  const { data: tenant, isLoading, error } = usePlatformTenant(id || null);
  const updateMutation = useUpdatePlatformTenant();
  const { formatDate } = useFormatting();

  const { data: usersData, isLoading: usersLoading } = useQuery({
    queryKey: ['platformTenantUsers', id],
    queryFn: () => platformApi.getTenantUsers(id!),
    enabled: !!id,
  });

  const users = usersData?.users ?? [];
  const enabledUsers = useMemo(() => users.filter((u) => u.is_enabled), [users]);
  const defaultUserId = useMemo(() => {
    const admin = users.find((u) => u.role === 'tenant_admin' && u.is_enabled);
    if (admin) return admin.id;
    return enabledUsers[0]?.id ?? null;
  }, [users, enabledUsers]);
  const [selectedUserId, setSelectedUserId] = useState<string | null>(null);
  const effectiveUserId = selectedUserId || defaultUserId;

  const { isImpersonating, stop: stopImpersonation, forceStop: forceStopImpersonation, status: impersonationStatus } = useImpersonation(true);
  const [stopImpersonationFailed, setStopImpersonationFailed] = useState(false);

  const impersonateMutation = useMutation({
    mutationFn: ({ tenantId, userId }: { tenantId: string; userId?: string }) =>
      platformApi.impersonateTenant(tenantId, userId),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['platform', 'impersonation'] });
      queryClient.invalidateQueries({ queryKey: IMPERSONATION_STATUS_UI_QUERY_KEY });
      const userName = variables.userId ? users.find((u) => u.id === variables.userId)?.name : tenant?.name;
      toast.success(`Now impersonating ${userName ?? 'tenant'}`);
      navigate('/app/dashboard');
    },
  });
  const storeReturnTo = useCallback(() => {
    sessionStorage.setItem(IMPERSONATION_RETURN_TO_KEY, location.pathname);
  }, [location.pathname]);

  const [editOpen, setEditOpen] = useState(false);
  const [editForm, setEditForm] = useState<UpdatePlatformTenantPayload>({});
  const [resetPasswordOpen, setResetPasswordOpen] = useState(false);
  const [resetPasswordMode, setResetPasswordMode] = useState<'token' | 'direct'>('token');
  const [resetPasswordValue, setResetPasswordValue] = useState('');
  const [resetTokenResult, setResetTokenResult] = useState<string | null>(null);

  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteLockInitialAdmin, setInviteLockInitialAdmin] = useState(false);
  const [inviteForm, setInviteForm] = useState({ email: '', role: 'operator' as 'tenant_admin' | 'accountant' | 'operator' });
  const [inviteResult, setInviteResult] = useState<{
    invite_link: string;
    expires_in_hours: number;
    email: string;
    role: string;
  } | null>(null);

  const [createUserOpen, setCreateUserOpen] = useState(false);
  const [createUserForm, setCreateUserForm] = useState({ name: '', email: '', role: 'operator' as 'tenant_admin' | 'accountant' | 'operator' });
  const [createUserResult, setCreateUserResult] = useState<{ user: { name: string; email: string }; temporary_password: string } | null>(null);
  const createUserMutation = useMutation({
    mutationFn: (payload: { name: string; email: string; role: 'tenant_admin' | 'accountant' | 'operator' }) =>
      platformApi.createTenantUser(id!, payload),
    onSuccess: (data) => {
      setCreateUserResult({ user: data.user, temporary_password: data.temporary_password });
      queryClient.invalidateQueries({ queryKey: ['platformTenantUsers', id] });
    },
    onError: (e: Error) => {
      toast.error(e?.message ?? 'Failed to create user');
    },
  });

  const updateUserMutation = useMutation({
    mutationFn: (payload: { userId: string; role?: 'tenant_admin' | 'accountant' | 'operator'; is_enabled?: boolean }) => {
      const body = { role: payload.role, is_enabled: payload.is_enabled };
      if (import.meta.env.DEV) {
        console.log('[PlatformTenantDetail] updateUser', { userId: payload.userId, payload: body });
      }
      return platformApi.updateTenantUser(id!, payload.userId, body);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platformTenantUsers', id] });
      toast.success('User updated');
    },
    onError: (e: unknown) => {
      const msg = getApiErrorMessage(e, 'Failed to update user');
      toast.error(msg);
    },
  });

  const lastEnabledAdminId = useMemo(() => {
    const admins = users.filter((u) => u.role === 'tenant_admin' && u.is_enabled);
    return admins.length === 1 ? admins[0].id : null;
  }, [users]);

  const platformInviteMutation = useMutation({
    mutationFn: (payload: { email: string; role?: 'tenant_admin' | 'accountant' | 'operator' }) =>
      platformApi.platformInviteTenantUser(id!, payload),
    onSuccess: (data) => {
      setInviteResult({
        invite_link: data.invite_link,
        expires_in_hours: data.expires_in_hours,
        email: data.email,
        role: data.role,
      });
      queryClient.invalidateQueries({ queryKey: ['platformTenantUsers', id] });
    },
    onError: (e: Error) => {
      const msg = e?.message ?? '';
      if (msg.includes('already exists') || msg.includes('User already exists')) {
        toast.error('User already exists in this tenant.');
      } else if (msg.includes('platform admin') || msg.includes('Cannot invite')) {
        toast.error(msg);
      } else if (msg.includes('Too many') || msg.includes('429')) {
        toast.error(msg || 'Too many attempts. Try again later.');
      } else {
        toast.error(msg || 'Failed to create invitation');
      }
    },
  });

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
        <div className="flex flex-wrap items-center gap-2">
          {usersLoading ? (
            <span className="text-sm text-gray-500">Loading users…</span>
          ) : users.length === 0 ? (
            <div className="flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
              <span>No users exist in this tenant yet.</span>
              <button
                type="button"
                onClick={() => {
                  setInviteLockInitialAdmin(true);
                  setInviteForm({ email: '', role: 'tenant_admin' });
                  setInviteResult(null);
                  setInviteOpen(true);
                }}
                className="px-3 py-1.5 bg-amber-600 text-white rounded-md hover:bg-amber-700 font-medium"
                data-testid="invite-initial-admin"
              >
                Invite initial admin
              </button>
              <span>or</span>
              <button
                type="button"
                onClick={() => {
                  setResetTokenResult(null);
                  setResetPasswordValue('');
                  setResetPasswordOpen(true);
                }}
                className="font-medium text-amber-700 underline hover:no-underline"
              >
                Reset admin password
              </button>
            </div>
          ) : (
            <>
              {isImpersonating ? (
                <div className="flex items-center gap-3 flex-wrap">
                  <span className="text-sm text-amber-800">
                    Impersonating {impersonationStatus?.user?.email ?? 'user'} in {impersonationStatus?.tenant?.name ?? tenant.name}
                  </span>
                  {stopImpersonationFailed && (
                    <button
                      type="button"
                      onClick={async () => {
                        setStopImpersonationFailed(false);
                        try {
                          await forceStopImpersonation();
                          queryClient.invalidateQueries({ queryKey: IMPERSONATION_STATUS_UI_QUERY_KEY });
                          queryClient.invalidateQueries({ queryKey: ['platformTenantUsers', id] });
                          toast.success('Stopped impersonation');
                        } catch (e: unknown) {
                          toast.error((e as Error)?.message ?? 'Force stop failed');
                        }
                      }}
                      className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 font-medium"
                      data-testid="force-stop-impersonation-tenant-detail"
                    >
                      Force stop
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={async () => {
                      setStopImpersonationFailed(false);
                      try {
                        await stopImpersonation(impersonationStatus?.tenant?.id);
                        queryClient.invalidateQueries({ queryKey: IMPERSONATION_STATUS_UI_QUERY_KEY });
                        queryClient.invalidateQueries({ queryKey: ['platformTenantUsers', id] });
                        toast.success('Stopped impersonation');
                      } catch {
                        setStopImpersonationFailed(true);
                        toast.error('Stop failed. Try "Force stop".');
                      }
                    }}
                    className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 font-medium"
                    data-testid="stop-impersonation-tenant-detail"
                  >
                    Stop impersonation
                  </button>
                </div>
              ) : (
                <>
                  <label className="flex items-center gap-2 text-sm text-gray-700">
                    <span>Impersonate as</span>
                    <select
                      value={effectiveUserId ?? ''}
                      onChange={(e) => setSelectedUserId(e.target.value || null)}
                      className="rounded border border-gray-300 px-2 py-1.5 text-sm"
                      data-testid="impersonate-user-select"
                    >
                      {users.map((u) => (
                        <option key={u.id} value={u.id} disabled={!u.is_enabled}>
                          {u.name} ({u.email}) {u.role !== 'tenant_admin' ? ` · ${u.role}` : ''}
                          {!u.is_enabled ? ' · disabled' : ''}
                        </option>
                      ))}
                    </select>
                  </label>
                  <button
                    type="button"
                    onClick={async () => {
                      if (!effectiveUserId) return;
                      try {
                        storeReturnTo();
                        await impersonateMutation.mutateAsync({
                          tenantId: tenant.id,
                          userId: effectiveUserId,
                        });
                        setTenantId(tenant.id);
                      } catch (e: unknown) {
                        const msg = (e as Error)?.message ?? '';
                        if (msg.includes('impersonation_nesting_not_allowed') || msg.includes('Already impersonating')) {
                          toast.error("Already impersonating. Click 'Stop impersonation' first.");
                        } else if (msg.includes('No users exist') || msg.includes('no_users_to_impersonate')) {
                          toast.error('No users exist in this tenant to impersonate. Use Reset admin password below to set access.');
                        } else {
                          toast.error(msg || 'Failed to start impersonation');
                        }
                      }
                    }}
                    disabled={
                      impersonateMutation.isPending ||
                      !effectiveUserId ||
                      !users.find((u) => u.id === effectiveUserId)?.is_enabled
                    }
                    className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50"
                    data-testid="impersonate-tenant-detail"
                  >
                    Impersonate
                  </button>
                </>
              )}
            </>
          )}
          <button
            type="button"
            onClick={() => {
              setEditForm({
                name: tenant.name,
                slug: tenant.slug ?? undefined,
                plan_key: tenant.plan_key ?? undefined,
                currency_code: tenant.currency_code,
                locale: tenant.locale,
                timezone: tenant.timezone,
              });
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
            {tenant.slug != null && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Slug</dt>
                <dd className="mt-1 text-sm text-gray-900 font-mono">{tenant.slug}</dd>
              </div>
            )}
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

        {/* Users */}
        <div className="bg-white rounded-lg shadow p-6 md:col-span-2">
          <div className="flex items-center justify-between gap-2 mb-4">
            <h2 className="text-lg font-semibold text-gray-900">Users</h2>
            {updateUserMutation.isPending && (
              <span className="text-sm text-amber-700 font-medium">Saving…</span>
            )}
          </div>
          {isImpersonating && (
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mb-4">
              Stop impersonation to manage users from the platform.
            </p>
          )}
          {usersLoading ? (
            <div className="flex justify-center py-8">
              <LoadingSpinner />
            </div>
          ) : users.length === 0 ? (
            <p className="text-sm text-gray-500 py-4">No users yet. Invite initial admin to get started.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {users.map((u) => {
                    const isLastEnabledAdmin = lastEnabledAdminId === u.id;
                    const cannotChange = isLastEnabledAdmin || isImpersonating;
                    return (
                      <tr key={u.id}>
                        <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{u.name}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{u.email}</td>
                        <td className="px-4 py-3 whitespace-nowrap">
                          <select
                            value={u.role}
                            disabled={cannotChange || updateUserMutation.isPending}
                            title={isImpersonating ? 'Stop impersonation to manage users.' : isLastEnabledAdmin ? 'Cannot remove the last tenant admin.' : undefined}
                            onChange={(e) => {
                              const newRole = e.target.value as 'tenant_admin' | 'accountant' | 'operator';
                              if (newRole !== u.role) {
                                updateUserMutation.mutate({ userId: u.id, role: newRole });
                              }
                            }}
                            className="rounded border border-gray-300 px-2 py-1 text-sm disabled:opacity-60 disabled:cursor-not-allowed"
                          >
                            <option value="tenant_admin">tenant_admin</option>
                            <option value="accountant">accountant</option>
                            <option value="operator">operator</option>
                          </select>
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap">
                          <label className="flex items-center gap-2" title={isImpersonating ? 'Stop impersonation to manage users.' : isLastEnabledAdmin ? 'Cannot remove the last tenant admin.' : undefined}>
                            <input
                              type="checkbox"
                              checked={u.is_enabled}
                              disabled={cannotChange || updateUserMutation.isPending}
                              onChange={(e) => {
                                if (cannotChange) return;
                                updateUserMutation.mutate({ userId: u.id, is_enabled: e.target.checked });
                              }}
                              className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C] disabled:opacity-60 disabled:cursor-not-allowed"
                            />
                            <span className={`text-xs font-semibold ${u.is_enabled ? 'text-green-800' : 'text-gray-600'}`}>
                              {u.is_enabled ? 'Enabled' : 'Disabled'}
                            </span>
                          </label>
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{formatDate(u.created_at)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* Support actions */}
        <div className="bg-white rounded-lg shadow p-6 md:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Support actions</h2>
          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={() => {
                setInviteLockInitialAdmin(false);
                setInviteForm({ email: '', role: 'operator' });
                setInviteResult(null);
                setInviteOpen(true);
              }}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm"
              data-testid="invite-user"
            >
              Invite user
            </button>
            <button
              type="button"
              onClick={() => {
                setCreateUserForm({ name: '', email: '', role: 'operator' });
                setCreateUserResult(null);
                setCreateUserOpen(true);
              }}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm"
              data-testid="create-user"
            >
              Create user
            </button>
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

      {/* Edit Modal — name, slug, timezone, locale, currency, plan. Status (archive/suspend) is in Support actions. */}
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
          <FormField label="Slug">
            <input
              type="text"
              value={editForm.slug ?? tenant.slug ?? ''}
              onChange={(e) => setEditForm({ ...editForm, slug: e.target.value || undefined })}
              placeholder="e.g. acme-farm"
              className="w-full border border-gray-300 rounded-md px-3 py-2 font-mono text-sm"
            />
            <p className="mt-1 text-xs text-amber-700">
              Lowercase, hyphens only. Changing slug affects subdomain login.
            </p>
          </FormField>
          <FormField label="Timezone">
            <input
              type="text"
              value={editForm.timezone ?? tenant.timezone}
              onChange={(e) => setEditForm({ ...editForm, timezone: e.target.value })}
              placeholder="e.g. Europe/London"
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            />
          </FormField>
          <FormField label="Locale">
            <input
              type="text"
              value={editForm.locale ?? tenant.locale}
              onChange={(e) => setEditForm({ ...editForm, locale: e.target.value })}
              placeholder="e.g. en-GB"
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            />
          </FormField>
          <FormField label="Currency">
            <input
              type="text"
              value={editForm.currency_code ?? tenant.currency_code}
              onChange={(e) => setEditForm({ ...editForm, currency_code: e.target.value })}
              placeholder="e.g. GBP"
              className="w-full border border-gray-300 rounded-md px-3 py-2"
              maxLength={10}
            />
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

      {/* Invite user / Invite initial admin modal */}
      <Modal
        isOpen={inviteOpen}
        onClose={() => {
          setInviteOpen(false);
          setInviteResult(null);
        }}
        title={inviteLockInitialAdmin ? 'Invite initial admin' : 'Invite user'}
        size="md"
      >
        {inviteResult ? (
          <div className="space-y-3">
            <p className="text-sm text-gray-600">
              Share this link with <strong>{inviteResult.email}</strong> (role: {inviteResult.role}). Expires in {inviteResult.expires_in_hours} hours.
            </p>
            <div className="flex gap-2">
              <input
                type="text"
                readOnly
                value={inviteResult.invite_link}
                className="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm bg-gray-50 font-mono"
              />
              <button
                type="button"
                onClick={() => {
                  navigator.clipboard.writeText(inviteResult.invite_link);
                  toast.success('Link copied');
                }}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
              >
                Copy
              </button>
            </div>
            <div className="flex justify-end pt-2">
              <button
                type="button"
                onClick={() => {
                  setInviteOpen(false);
                  setInviteResult(null);
                }}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
              >
                Done
              </button>
            </div>
          </div>
        ) : (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              const email = inviteForm.email.trim();
              if (!email) return;
              platformInviteMutation.mutate({
                email,
                role: inviteLockInitialAdmin ? 'tenant_admin' : inviteForm.role,
              });
            }}
            className="space-y-4"
          >
            <FormField label="Email">
              <input
                type="email"
                value={inviteForm.email}
                onChange={(e) => setInviteForm({ ...inviteForm, email: e.target.value })}
                placeholder="user@example.com"
                className="w-full border border-gray-300 rounded-md px-3 py-2"
                required
              />
            </FormField>
            <FormField label="Role">
              {inviteLockInitialAdmin ? (
                <input type="text" readOnly value="tenant_admin" className="w-full border border-gray-300 rounded-md px-3 py-2 bg-gray-50" />
              ) : (
                <select
                  value={inviteForm.role}
                  onChange={(e) => setInviteForm({ ...inviteForm, role: e.target.value as 'tenant_admin' | 'accountant' | 'operator' })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2"
                >
                  <option value="tenant_admin">tenant_admin</option>
                  <option value="accountant">accountant</option>
                  <option value="operator">operator</option>
                </select>
              )}
            </FormField>
            <div className="flex justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => { setInviteOpen(false); setInviteResult(null); }}
                className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={platformInviteMutation.isPending || !inviteForm.email.trim()}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {platformInviteMutation.isPending ? 'Creating...' : 'Create invitation'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      {/* Create user modal (platform) */}
      <Modal
        isOpen={createUserOpen}
        onClose={() => { setCreateUserOpen(false); setCreateUserResult(null); }}
        title="Create user"
        size="md"
      >
        {createUserResult ? (
          <div className="space-y-4">
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded p-3">
              This password is shown only once. Share it securely with {createUserResult.user.name}. They must change it on first login.
            </p>
            <FormField label="Temporary password">
              <div className="flex gap-2">
                <input
                  type="text"
                  readOnly
                  value={createUserResult.temporary_password}
                  className="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm font-mono bg-gray-50"
                />
                <button
                  type="button"
                  onClick={() => {
                    navigator.clipboard.writeText(createUserResult.temporary_password);
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
                onClick={() => { setCreateUserOpen(false); setCreateUserResult(null); }}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
              >
                Done
              </button>
            </div>
          </div>
        ) : (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              if (!createUserForm.name.trim() || !createUserForm.email.trim()) return;
              createUserMutation.mutate({
                name: createUserForm.name.trim(),
                email: createUserForm.email.trim(),
                role: createUserForm.role,
              });
            }}
            className="space-y-4"
          >
            <FormField label="Name" required>
              <input
                type="text"
                value={createUserForm.name}
                onChange={(e) => setCreateUserForm({ ...createUserForm, name: e.target.value })}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
                required
              />
            </FormField>
            <FormField label="Email" required>
              <input
                type="email"
                value={createUserForm.email}
                onChange={(e) => setCreateUserForm({ ...createUserForm, email: e.target.value })}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
                required
              />
            </FormField>
            <FormField label="Role" required>
              <select
                value={createUserForm.role}
                onChange={(e) => setCreateUserForm({ ...createUserForm, role: e.target.value as 'tenant_admin' | 'accountant' | 'operator' })}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
              >
                <option value="tenant_admin">tenant_admin</option>
                <option value="accountant">accountant</option>
                <option value="operator">operator</option>
              </select>
            </FormField>
            <div className="flex justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => { setCreateUserOpen(false); setCreateUserResult(null); }}
                className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={createUserMutation.isPending}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {createUserMutation.isPending ? 'Creating...' : 'Create'}
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}
