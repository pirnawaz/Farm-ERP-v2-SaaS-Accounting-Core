import type { UserRole } from '../types';

const ROLES: UserRole[] = ['platform_admin', 'tenant_admin', 'accountant', 'operator'];

const PERMISSIONS = {
  platform_admin: {
    'Manage tenants': true,
    'View all tenants': true,
    'Enable/disable modules per tenant': true,
  },
  tenant_admin: {
    'Manage users': true,
    'Assign roles': true,
    'Enable/disable modules': true,
    'POST accounting documents': true,
    'REVERSE documents': true,
    'Manage share rules & settlements': true,
    'Close/open crop cycles': true,
    'View all data': true,
  },
  accountant: {
    'POST accounting documents': true,
    'REVERSE documents': true,
    'Manage share rules & settlements': true,
    'View all data': true,
    'Create/edit transactions': true,
  },
  operator: {
    'Create/edit own transactions': true,
    'View data': true,
  },
};

export default function AdminRolesPage() {
  const allPermissions = new Set<string>();
  Object.values(PERMISSIONS).forEach(rolePerms => {
    Object.keys(rolePerms).forEach(perm => allPermissions.add(perm));
  });

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Role Permissions Matrix</h1>
        <p className="text-sm text-gray-500 mt-1">Overview of permissions for each role. This is a read-only reference.</p>
      </div>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200" role="table" aria-label="Role permissions matrix">
            <thead className="bg-gray-50">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" aria-label="Permission">
                  Permission
                </th>
                {ROLES.map((role) => (
                  <th
                    key={role}
                    scope="col"
                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                    aria-label={role}
                  >
                    {role.replace('_', ' ')}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {Array.from(allPermissions).sort().map((permission) => (
                <tr key={permission}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{permission}</td>
                  {ROLES.map((role) => (
                    <td key={role} className="px-6 py-4 whitespace-nowrap text-center">
                      {PERMISSIONS[role]?.[permission] ? (
                        <span className="text-green-600 font-semibold">✓</span>
                      ) : (
                        <span className="text-gray-300">-</span>
                      )}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 className="text-sm font-semibold text-blue-900 mb-2">Important Notes</h3>
        <ul className="text-sm text-blue-800 space-y-1 list-disc list-inside">
          <li>POST and REVERSE actions are restricted to tenant_admin and accountant roles only.</li>
          <li>Only tenant_admin can manage users and assign roles.</li>
          <li>Only tenant_admin can close/open crop cycles.</li>
          <li>Operators can create and edit their own transactions but cannot post them.</li>
        </ul>
      </div>
    </div>
  );
}
