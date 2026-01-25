import { useState, useEffect, useCallback } from 'react';

const TENANT_ID_KEY = 'farm_erp_tenant_id';

export function useTenant() {
  const [tenantId, setTenantIdState] = useState<string>(() => {
    return localStorage.getItem(TENANT_ID_KEY) || '';
  });

  const setTenantId = useCallback((id: string) => {
    localStorage.setItem(TENANT_ID_KEY, id);
    setTenantIdState(id);
  }, []);

  useEffect(() => {
    const stored = localStorage.getItem(TENANT_ID_KEY);
    if (stored && stored !== tenantId) {
      setTenantIdState(stored);
    }
  }, [tenantId]);

  return { tenantId, setTenantId };
}
