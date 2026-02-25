import { useState, useEffect, useRef, useCallback } from 'react';

const AUTOSAVE_PREFIX = 'farm_erp_autosave_';

function buildSaveKey(tenantId: string, formId: string, context?: Record<string, string>): string {
  const base = `${tenantId}:${formId}`;
  if (!context || Object.keys(context).length === 0) return base;
  const ctx = Object.keys(context)
    .sort()
    .map((k) => `${k}=${context[k] ?? ''}`)
    .join('&');
  return `${base}:${ctx}`;
}

function getStoredDraft(key: string): unknown | null {
  try {
    const raw = localStorage.getItem(AUTOSAVE_PREFIX + key);
    if (raw == null) return null;
    return JSON.parse(raw) as unknown;
  } catch {
    return null;
  }
}

function setStoredDraft(key: string, value: unknown): void {
  try {
    localStorage.setItem(AUTOSAVE_PREFIX + key, JSON.stringify(value));
  } catch {
    // ignore
  }
}

function removeStoredDraft(key: string): void {
  try {
    localStorage.removeItem(AUTOSAVE_PREFIX + key);
  } catch {
    // ignore
  }
}

export interface UseFormAutosaveOptions<T> {
  formId: string;
  tenantId: string;
  context?: Record<string, string>;
  getSnapshot: () => T;
  applySnapshot: (data: T) => void;
  debounceMs?: number;
  /** Skip autosave when true (e.g. editing existing record) */
  disabled?: boolean;
}

export interface UseFormAutosaveReturn<T> {
  hasDraft: boolean;
  draftData: T | null;
  restore: () => void;
  discard: () => void;
  clearDraft: () => void;
}

export function useFormAutosave<T extends object>({
  formId,
  tenantId,
  context,
  getSnapshot,
  applySnapshot,
  debounceMs = 4000,
  disabled = false,
}: UseFormAutosaveOptions<T>): UseFormAutosaveReturn<T> {
  const saveKey = buildSaveKey(tenantId, formId, context);
  const [hasDraft, setHasDraft] = useState(false);
  const [draftData, setDraftData] = useState<T | null>(null);
  const initialCheckDone = useRef(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // On mount: check for existing draft (only for new forms, not when disabled)
  useEffect(() => {
    if (disabled) return;
    if (initialCheckDone.current) return;
    initialCheckDone.current = true;
    const stored = getStoredDraft(saveKey);
    if (stored && typeof stored === 'object' && stored !== null) {
      setDraftData(stored as T);
      setHasDraft(true);
    }
  }, [saveKey, disabled]);

  const clearDraft = useCallback(() => {
    removeStoredDraft(saveKey);
    setHasDraft(false);
    setDraftData(null);
  }, [saveKey]);

  const restore = useCallback(() => {
    if (draftData) {
      applySnapshot(draftData);
      clearDraft();
    }
  }, [draftData, applySnapshot, clearDraft]);

  const discard = useCallback(() => {
    clearDraft();
  }, [clearDraft]);

  // Debounced autosave when snapshot changes. Call getSnapshot in effect so consumer can memoize it with form state deps.
  const mountedRef = useRef(false);
  useEffect(() => {
    if (disabled) return;
    if (!mountedRef.current) {
      mountedRef.current = true;
      return;
    }
    const snapshot = getSnapshot();
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setStoredDraft(saveKey, snapshot);
      debounceRef.current = null;
    }, debounceMs);
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [saveKey, getSnapshot, debounceMs, disabled]);

  return { hasDraft, draftData, restore, discard, clearDraft };
}
