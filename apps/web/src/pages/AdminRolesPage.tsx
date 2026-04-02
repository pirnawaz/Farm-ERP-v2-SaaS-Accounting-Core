import type { UserRole } from '../types';
import { ALL_PERMISSION_KEYS, PERMISSION_LABELS, getPermissionsForRole } from '../config/permissions';
import { PageContainer } from '../components/PageContainer';

const ROLES: UserRole[] = ['platform_admin', 'tenant_admin', 'accountant', 'operator'];

export default function AdminRolesPage() {
  return (
    <PageContainer className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Role Permissions Matrix</h1>
        <p className="text-sm text-gray-500 mt-1">Overview of permissions for each role. This is a read-only reference and matches backend enforcement.</p>
      </div>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200" role="table" aria-label="Role permissions matrix">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th scope="col" className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" aria-label="Permission">
                  Permission
                </th>
                {ROLES.map((role) => (
                  <th
                    key={role}
                    scope="col"
                    className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                    aria-label={role}
                  >
                    {role.replace('_', ' ')}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {ALL_PERMISSION_KEYS.map((permission) => (
                <tr key={permission}>
                  <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-normal break-words text-sm text-gray-900">
                    {PERMISSION_LABELS[permission]}
                  </td>
                  {ROLES.map((role) => {
                    const allowed = getPermissionsForRole(role).has(permission);
                    return (
                      <td key={role} className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-center">
                        {allowed ? (
                          <span className="text-green-600 font-semibold">✓</span>
                        ) : (
                          <span className="text-gray-300">-</span>
                        )}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-4">
        <h3 className="text-sm font-semibold text-[#2D3A3A] mb-2">Important Notes</h3>
        <ul className="text-sm text-[#2D3A3A] space-y-1 list-disc list-inside">
          <li>Platform admin is the only role that can manage tenants, view all tenants, and enable/disable modules per tenant.</li>
          <li>Tenant admin has full access within their tenant (users, roles, modules, cycles, post/reverse, settlements).</li>
          <li>Accountant can post, reverse, manage settlements, and create/edit transactions; they cannot manage users, assign roles, or enable/disable modules.</li>
          <li>Operators can create/edit their own transactions and view data; they cannot post, reverse, or manage users/modules/cycles.</li>
        </ul>
      </div>
    </PageContainer>
  );
}
