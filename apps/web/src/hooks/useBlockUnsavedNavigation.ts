import { useEffect } from 'react';

/**
 * Warn when closing the tab or refreshing with unsaved form state.
 * Does not intercept in-app navigation (would need a router blocker).
 */
export function useBlockUnsavedNavigation(dirty: boolean, message = 'You have unsaved changes. Leave this page?'): void {
  useEffect(() => {
    if (!dirty) return;
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = message;
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [dirty, message]);
}
