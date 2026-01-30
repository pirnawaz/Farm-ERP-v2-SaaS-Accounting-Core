const TENANT_ID_KEY = 'farm_erp_tenant_id'
const USER_ROLE_KEY = 'farm_erp_user_role'
const USER_ID_KEY = 'farm_erp_user_id'
// In development, use relative URLs to leverage Vite proxy
// In production, use full URL from env or default
const API_BASE_URL = import.meta.env.VITE_API_URL || (import.meta.env.DEV ? '' : 'http://localhost:8000')

function getTenantId(): string | null {
  return localStorage.getItem(TENANT_ID_KEY)
}

function getUserRole(): string | null {
  return localStorage.getItem(USER_ROLE_KEY)
}

function getUserId(): string | null {
  return localStorage.getItem(USER_ID_KEY)
}

async function request<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<T> {
  const tenantId = getTenantId()
  const userRole = getUserRole()
  
  // For non-dev and non-platform routes, tenant is required
  const isDevRoute = endpoint.includes('/api/dev/') || endpoint.includes('api/dev/')
  const isPlatformRoute = endpoint.includes('/api/platform/') || endpoint.includes('api/platform/')
  if (!isDevRoute && !isPlatformRoute && !tenantId) {
    throw new Error('No tenant selected. Please select a tenant.')
  }
  
  const url = endpoint.startsWith('http') ? endpoint : `${API_BASE_URL}${endpoint}`
  
  // Build headers - always include tenant and role if available
  // Handle different header types (Headers object, plain object, or array)
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }
  
  // Convert existing headers to plain object if needed
  if (options.headers) {
    if (options.headers instanceof Headers) {
      options.headers.forEach((value, key) => {
        headers[key] = value
      })
    } else if (Array.isArray(options.headers)) {
      options.headers.forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          headers[key] = String(value)
        }
      })
    } else {
      // Plain object
      Object.entries(options.headers).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          headers[key] = String(value)
        }
      })
    }
  }
  
  // Always set tenant and role headers if available (they override any existing values)
  // Do not send X-Tenant-Id for platform routes
  if (!isPlatformRoute && tenantId && tenantId.trim()) {
    headers['X-Tenant-Id'] = tenantId.trim()
  }
  
  if (userRole && userRole.trim()) {
    headers['X-User-Role'] = userRole.trim()
  }

  const userId = getUserId()
  if (userId && userId.trim()) {
    headers['X-User-Id'] = userId.trim()
  }

  try {
    const response = await fetch(url, {
      ...options,
      headers,
      credentials: 'include', // Include cookies (httpOnly auth token)
    })

    // Get response text first to check content type
    const text = await response.text()
    
    if (!response.ok) {
      // Check if response is HTML (error page) instead of JSON
      const contentType = response.headers.get('content-type') || ''
      if (contentType.includes('text/html') || text.trim().startsWith('<!')) {
        throw new Error(`Server returned HTML instead of JSON (HTTP ${response.status}). This usually indicates a server error. Check the backend logs.`)
      }
      
      // Try to parse as JSON, fallback to status text
      try {
        const error = JSON.parse(text)
        throw new Error(error.error || error.message || `HTTP ${response.status}: ${response.statusText}`)
      } catch (parseError) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}${text ? ` - ${text.substring(0, 100)}` : ''}`)
      }
    }

    // Handle empty responses
    if (!text) {
      return {} as T
    }

    // Check if response is HTML (shouldn't happen for successful requests, but handle it)
    const contentType = response.headers.get('content-type') || ''
    if (contentType.includes('text/html') || text.trim().startsWith('<!')) {
      throw new Error('Server returned HTML instead of JSON. Check the API endpoint and server configuration.')
    }

    // Parse JSON response
    try {
      return JSON.parse(text) as T
    } catch (parseError) {
      throw new Error(`Invalid JSON response from server: ${text.substring(0, 200)}`)
    }
  } catch (error) {
    // Handle network errors (CORS, connection refused, etc.)
    if (error instanceof TypeError && error.message.includes('fetch')) {
      const apiUrl = API_BASE_URL || 'the API server (via proxy)'
      throw new Error(`Network error: Unable to connect to ${apiUrl}. Please check if the API server is running.`)
    }
    // Re-throw other errors as-is
    throw error
  }
}

function buildQueryString(params: Record<string, string | number | undefined | null>): string {
  const searchParams = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      searchParams.append(key, String(value))
    }
  })
  const query = searchParams.toString()
  return query ? `?${query}` : ''
}

export const apiClient = {
  get: <T>(endpoint: string): Promise<T> => {
    return request<T>(endpoint, { method: 'GET', cache: 'no-store' })
  },

  post: <T>(endpoint: string, data: unknown): Promise<T> => {
    return request<T>(endpoint, {
      method: 'POST',
      body: JSON.stringify(data),
    })
  },

  patch: <T>(endpoint: string, data: unknown): Promise<T> => {
    return request<T>(endpoint, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  },

  put: <T>(endpoint: string, data: unknown): Promise<T> => {
    return request<T>(endpoint, {
      method: 'PUT',
      body: JSON.stringify(data),
    })
  },

  delete: <T>(endpoint: string, data?: unknown): Promise<T> => {
    return request<T>(endpoint, {
      method: 'DELETE',
      body: data ? JSON.stringify(data) : undefined,
    })
  },

  // Phase 6: Reporting methods
  getTrialBalance: (params: {
    from: string
    to: string
    project_id?: string
    currency_code?: string
  }) => {
    const query = buildQueryString(params)
    return request<import('./types').TrialBalanceRow[]>(`/api/reports/trial-balance${query}`)
  },

  getGeneralLedger: (params: {
    from: string
    to: string
    account_id?: string
    project_id?: string
    page?: number
    per_page?: number
  }) => {
    const query = buildQueryString(params)
    return request<import('./types').GeneralLedgerResponse>(`/api/reports/general-ledger${query}`)
  },

  getProjectPL: (params: {
    from: string
    to: string
    project_id?: string
  }) => {
    const query = buildQueryString(params)
    return request<import('./types').ProjectPLRow[]>(`/api/reports/project-pl${query}`)
  },

  getCropCyclePL: (params: {
    from: string
    to: string
    crop_cycle_id?: string
  }) => {
    const query = buildQueryString(params)
    return request<import('./types').CropCyclePLRow[]>(`/api/reports/crop-cycle-pl${query}`)
  },

  getAccountBalances: (params: {
    as_of: string
    project_id?: string
  }) => {
    const query = buildQueryString(params)
    return request<import('./types').AccountBalanceRow[]>(`/api/reports/account-balances${query}`)
  },

  reconcileProject: (params: {
    project_id: string
    from: string
    to: string
  }) => {
    const query = buildQueryString(params)
    return request<import('./types').ReconciliationResponse>(`/api/reports/reconciliation/project${query}`)
  },

  reconcileCropCycle: (params: {
    crop_cycle_id: string
    from: string
    to: string
  }) => {
    const query = buildQueryString(params)
    return request<import('./types').ReconciliationResponse>(`/api/reports/reconciliation/crop-cycle${query}`)
  },

  reconcileSupplierAp: (params: {
    party_id: string
    from: string
    to: string
  }) => {
    const query = buildQueryString(params)
    return request<import('./types').ReconciliationResponse>(`/api/reports/reconciliation/supplier-ap${query}`)
  },
}
