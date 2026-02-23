import {
  createContext,
  useContext,
  useCallback,
  useState,
  useEffect,
  useMemo,
  type ReactNode,
} from 'react';

const STORAGE_PREFIX = 'terrava.scope.';

export type CropCycleScopeType = 'all' | 'crop_cycle';

export interface CropCycleScopeState {
  scopeType: CropCycleScopeType;
  cropCycleId: string | undefined;
  /** True when state was restored from localStorage for the current tenant; false when no stored value. */
  initializedFromStorage: boolean;
}

interface CropCycleScopeContextType extends CropCycleScopeState {
  setScope: (scopeType: CropCycleScopeType, cropCycleId?: string) => void;
}

const defaultState: CropCycleScopeState = {
  scopeType: 'all',
  cropCycleId: undefined,
  initializedFromStorage: false,
};

const CropCycleScopeContext = createContext<CropCycleScopeContextType | undefined>(undefined);

function getStorageKey(tenantId: string | null): string | null {
  if (!tenantId) return null;
  return `${STORAGE_PREFIX}${tenantId}`;
}

function loadStoredScope(tenantId: string | null): CropCycleScopeState | null {
  const key = getStorageKey(tenantId);
  if (!key) return null;
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { scopeType?: string; cropCycleId?: string };
    if (parsed?.scopeType === 'crop_cycle' && typeof parsed.cropCycleId === 'string') {
      return {
        scopeType: 'crop_cycle',
        cropCycleId: parsed.cropCycleId,
        initializedFromStorage: true,
      };
    }
    if (parsed?.scopeType === 'all' || !parsed?.scopeType) {
      return {
        scopeType: 'all',
        cropCycleId: undefined,
        initializedFromStorage: true,
      };
    }
    return null;
  } catch {
    return null;
  }
}

function saveScope(tenantId: string | null, state: CropCycleScopeState): void {
  const key = getStorageKey(tenantId);
  if (!key) return;
  try {
    localStorage.setItem(
      key,
      JSON.stringify({
        scopeType: state.scopeType,
        cropCycleId: state.cropCycleId ?? undefined,
      })
    );
  } catch {
    // ignore
  }
}

export function CropCycleScopeProvider({
  children,
  tenantId,
}: {
  children: ReactNode;
  tenantId: string | null;
}) {
  const [state, setState] = useState<CropCycleScopeState>(() => {
    const stored = loadStoredScope(tenantId);
    if (stored) return stored;
    return { ...defaultState, initializedFromStorage: false };
  });

  useEffect(() => {
    const stored = loadStoredScope(tenantId);
    if (stored) {
      setState(stored);
    } else {
      setState({
        scopeType: 'all',
        cropCycleId: undefined,
        initializedFromStorage: false,
      });
    }
  }, [tenantId]);

  const setScope = useCallback(
    (scopeType: CropCycleScopeType, cropCycleId?: string) => {
      const next: CropCycleScopeState = {
        scopeType,
        cropCycleId: scopeType === 'crop_cycle' ? cropCycleId : undefined,
        initializedFromStorage: true,
      };
      setState(next);
      saveScope(tenantId, next);
    },
    [tenantId]
  );

  const value = useMemo<CropCycleScopeContextType>(
    () => ({
      ...state,
      setScope,
    }),
    [state.scopeType, state.cropCycleId, state.initializedFromStorage, setScope]
  );

  return (
    <CropCycleScopeContext.Provider value={value}>
      {children}
    </CropCycleScopeContext.Provider>
  );
}

export function useCropCycleScope(): CropCycleScopeContextType {
  const ctx = useContext(CropCycleScopeContext);
  if (ctx === undefined) {
    throw new Error('useCropCycleScope must be used within a CropCycleScopeProvider');
  }
  return ctx;
}
