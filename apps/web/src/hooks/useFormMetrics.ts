/**
 * Lightweight form friction metrics: form_opened, form_submitted, submission_error.
 * Logs to console (dev) and optionally appends to localStorage for internal review.
 * Does not send data to any third party.
 */

const STORAGE_KEY = 'farm_erp_form_metrics';
const MAX_ENTRIES = 100;

export type FormMetricEvent = 'form_opened' | 'form_submitted' | 'submission_error';

function persist(event: FormMetricEvent, formId: string, detail?: string) {
  const entry = {
    event,
    formId,
    detail: detail ?? undefined,
    ts: new Date().toISOString(),
  };
  if (typeof console !== 'undefined' && console.debug) {
    console.debug('[form_metrics]', entry);
  }
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const list: typeof entry[] = raw ? JSON.parse(raw) : [];
    list.push(entry);
    const trimmed = list.slice(-MAX_ENTRIES);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(trimmed));
  } catch {
    // ignore storage errors
  }
}

export function useFormMetrics(formId: string) {
  return {
    trackFormOpened: () => persist('form_opened', formId),
    trackFormSubmitted: () => persist('form_submitted', formId),
    trackSubmissionError: (message?: string) => persist('submission_error', formId, message),
  };
}
