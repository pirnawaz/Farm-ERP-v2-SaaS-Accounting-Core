import { useState, useEffect, useCallback } from 'react';

const TENANT_ID_KEY = 'farm_erp_tenant_id';

const TENANT_CHANGED = 'farm_erp_tenant_changed';

export function useTenant() {
  const [tenantId, setTenantIdState] = useState<string>(() => {
    return localStorage.getItem(TENANT_ID_KEY) || '';
  });

  const setTenantId = useCallback((id: string) => {
    if (id) {
      localStorage.setItem(TENANT_ID_KEY, id);
    } else {
      localStorage.removeItem(TENANT_ID_KEY);
    }
    setTenantIdState(id);
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent(TENANT_CHANGED));
    }
  }, []);

  useEffect(() => {
    const syncFromStorage = () => {
      setTenantIdState(localStorage.getItem(TENANT_ID_KEY) || '');
    };
    window.addEventListener(TENANT_CHANGED, syncFromStorage);
    return () => window.removeEventListener(TENANT_CHANGED, syncFromStorage);
  }, []);

  useEffect(() => {
    const stored = localStorage.getItem(TENANT_ID_KEY);
    if (stored && stored !== tenantId) {
      setTenantIdState(stored);
    }
  }, [tenantId]);

  return { tenantId, setTenantId };
}
