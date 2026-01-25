import { useEffect, useState } from 'react'
import { apiClient } from '@farm-erp/shared'

interface HealthResponse {
  ok: boolean
  service: string
  sha?: string
}

function HealthPage() {
  const [health, setHealth] = useState<HealthResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fetchHealth = async () => {
      try {
        setLoading(true)
        setError(null)
        const data = await apiClient.get<HealthResponse>('/api/health')
        setHealth(data)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch health')
      } finally {
        setLoading(false)
      }
    }

    fetchHealth()
  }, [])

  return (
    <div className="bg-white rounded-lg shadow p-6">
      <h2 className="text-2xl font-bold mb-4">API Health Check</h2>
      
      {loading && <p className="text-gray-600">Loading...</p>}
      
      {error && (
        <div className="bg-red-50 border border-red-200 rounded p-4">
          <p className="text-red-800">Error: {error}</p>
        </div>
      )}
      
      {health && (
        <div className="space-y-2">
          <div className="flex items-center">
            <span className="font-semibold mr-2">Status:</span>
            <span className={health.ok ? 'text-green-600' : 'text-red-600'}>
              {health.ok ? 'OK' : 'Error'}
            </span>
          </div>
          <div>
            <span className="font-semibold mr-2">Service:</span>
            <span>{health.service}</span>
          </div>
          {health.sha && (
            <div>
              <span className="font-semibold mr-2">SHA:</span>
              <span className="font-mono text-sm">{health.sha}</span>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default HealthPage
