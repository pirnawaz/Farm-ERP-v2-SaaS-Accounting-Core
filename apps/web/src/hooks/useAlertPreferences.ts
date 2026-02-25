/**
 * Alert preferences: read/write to localStorage key terrava.alerts.<tenantId>.
 * Frontend-only; no API.
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTenant } from './useTenant';
import type { AlertPreferences, OverdueBucket } from '../types/alertPreferences';
import type { AlertType } from '../types/alerts';

const STORAGE_PREFIX = 'terrava.alerts.';

const DEFAULT_PREFERENCES: AlertPreferences = {
  enabled: {
    PENDING_REVIEW: true,
    OVERDUE_CUSTOMERS: true,
    UNPAID_LABOUR: true,
    LOW_STOCK: false,
    NEGATIVE_MARGIN_FIELDS: true,
  },
  overdueBucket: '31_60',
  negativeMarginThreshold: 0,
  showComingSoon: false,
};

function getStorageKey(tenantId: string): string {
  return `${STORAGE_PREFIX}${tenantId || '_default'}`;
}

function loadPreferences(tenantId: string): AlertPreferences {
  try {
    const raw = localStorage.getItem(getStorageKey(tenantId));
    if (!raw) return { ...DEFAULT_PREFERENCES };
    const parsed = JSON.parse(raw) as Partial<AlertPreferences>;
    return {
      enabled: { ...DEFAULT_PREFERENCES.enabled, ...(parsed.enabled ?? {}) },
      overdueBucket: parsed.overdueBucket ?? DEFAULT_PREFERENCES.overdueBucket,
      negativeMarginThreshold:
        typeof parsed.negativeMarginThreshold === 'number'
          ? parsed.negativeMarginThreshold
          : DEFAULT_PREFERENCES.negativeMarginThreshold,
      showComingSoon:
        typeof parsed.showComingSoon === 'boolean'
          ? parsed.showComingSoon
          : DEFAULT_PREFERENCES.showComingSoon,
    };
  } catch {
    return { ...DEFAULT_PREFERENCES };
  }
}

function savePreferences(tenantId: string, prefs: AlertPreferences): void {
  try {
    localStorage.setItem(getStorageKey(tenantId), JSON.stringify(prefs));
  } catch {
    // ignore
  }
}

export function useAlertPreferences(): {
  preferences: AlertPreferences;
  setEnabled: (type: AlertType, value: boolean) => void;
  setOverdueBucket: (bucket: OverdueBucket) => void;
  setNegativeMarginThreshold: (value: number) => void;
  setShowComingSoon: (value: boolean) => void;
  resetToDefaults: () => void;
} {
  const { tenantId } = useTenant();
  const [preferences, setPreferencesState] = useState<AlertPreferences>(() =>
    loadPreferences(tenantId)
  );

  useEffect(() => {
    setPreferencesState(loadPreferences(tenantId));
  }, [tenantId]);

  const setPreferences = useCallback(
    (next: AlertPreferences) => {
      setPreferencesState(next);
      savePreferences(tenantId, next);
    },
    [tenantId]
  );

  const setEnabled = useCallback(
    (type: AlertType, value: boolean) => {
      setPreferences({
        ...preferences,
        enabled: { ...preferences.enabled, [type]: value },
      });
    },
    [preferences, setPreferences]
  );

  const setOverdueBucket = useCallback(
    (bucket: OverdueBucket) => {
      setPreferences({ ...preferences, overdueBucket: bucket });
    },
    [preferences, setPreferences]
  );

  const setNegativeMarginThreshold = useCallback(
    (value: number) => {
      setPreferences({ ...preferences, negativeMarginThreshold: value });
    },
    [preferences, setPreferences]
  );

  const setShowComingSoon = useCallback(
    (value: boolean) => {
      setPreferences({ ...preferences, showComingSoon: value });
    },
    [preferences, setPreferences]
  );

  const resetToDefaults = useCallback(() => {
    const def = { ...DEFAULT_PREFERENCES };
    setPreferencesState(def);
    savePreferences(tenantId, def);
  }, [tenantId]);

  return useMemo(
    () => ({
      preferences,
      setEnabled,
      setOverdueBucket,
      setNegativeMarginThreshold,
      setShowComingSoon,
      resetToDefaults,
    }),
    [
      preferences,
      setEnabled,
      setOverdueBucket,
      setNegativeMarginThreshold,
      setShowComingSoon,
      resetToDefaults,
    ]
  );
}
