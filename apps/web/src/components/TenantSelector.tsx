import { useEffect, useState } from 'react'

const TENANT_ID_KEY = 'farm_erp_tenant_id'

function TenantSelector() {
  const [selectedTenantId, setSelectedTenantId] = useState<string>('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const fetchTenants = async () => {
      try {
        // In a real app, you'd have a /api/tenants endpoint
        // For now, we'll use the seed tenant ID
        const stored = localStorage.getItem(TENANT_ID_KEY)
        if (stored) {
          setSelectedTenantId(stored)
        } else {
          // Default to seed tenant
          const defaultTenantId = '00000000-0000-0000-0000-000000000001'
          setSelectedTenantId(defaultTenantId)
          localStorage.setItem(TENANT_ID_KEY, defaultTenantId)
        }
      } catch (err) {
        console.error('Failed to fetch tenants', err)
      } finally {
        setLoading(false)
      }
    }

    fetchTenants()
  }, [])

  const handleChange = (tenantId: string) => {
    setSelectedTenantId(tenantId)
    localStorage.setItem(TENANT_ID_KEY, tenantId)
    // Reload page to reset API client with new tenant
    window.location.reload()
  }

  if (loading) {
    return <div className="text-sm text-gray-600">Loading...</div>
  }

  return (
    <div className="flex items-center space-x-2">
      <label className="text-sm font-medium text-gray-700">Tenant:</label>
      <select
        value={selectedTenantId}
        onChange={(e) => handleChange(e.target.value)}
        className="border border-gray-300 rounded px-3 py-1 text-sm"
      >
        <option value="00000000-0000-0000-0000-000000000001">Demo Farm</option>
      </select>
    </div>
  )
}

export default TenantSelector
