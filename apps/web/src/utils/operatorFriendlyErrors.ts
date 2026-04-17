/**
 * Map API / network errors to short operator-facing copy.
 * Does not change server behaviour — display layer only.
 */

export function extractApiErrorText(err: unknown): string | undefined {
  const x = err as {
    message?: string;
    response?: { data?: { error?: string; message?: string; errors?: Record<string, string[] | string> } };
  };
  const data = x?.response?.data;
  if (data?.errors && typeof data.errors === 'object') {
    const parts: string[] = [];
    for (const v of Object.values(data.errors)) {
      if (Array.isArray(v)) parts.push(...v);
      else if (typeof v === 'string') parts.push(v);
    }
    if (parts.length) return parts.join(' ');
  }
  return data?.error || data?.message || (typeof x?.message === 'string' ? x.message : undefined);
}

export type OperatorFriendlyError = { friendly: string; raw?: string };

/**
 * Turn a raw API message into friendlier copy when we recognise common patterns.
 */
export function toOperatorFriendlyMessage(raw: string | undefined): OperatorFriendlyError {
  if (!raw?.trim()) {
    return { friendly: '' };
  }
  const t = raw.trim();
  const lower = t.toLowerCase();

  if (
    lower.includes('insufficient') ||
    lower.includes('not enough') ||
    (lower.includes('stock') && (lower.includes('available') || lower.includes('on hand') || lower.includes('negative')))
  ) {
    return { friendly: 'Not enough stock available for this action.', raw: t };
  }
  if (
    lower.includes('project') &&
    (lower.includes('required') || lower.includes('missing') || lower.includes('select'))
  ) {
    return { friendly: 'Select a field cycle (project) before continuing.', raw: t };
  }
  if (lower.includes('crop cycle') && (lower.includes('required') || lower.includes('open'))) {
    return { friendly: 'Check the crop cycle or season is set and open.', raw: t };
  }
  if (lower.includes('rate') && (lower.includes('invalid') || lower.includes('greater than zero') || lower.includes('>'))) {
    return { friendly: 'Enter a valid rate greater than zero.', raw: t };
  }
  if (lower.includes('meter') && lower.includes('end')) {
    return { friendly: 'Check meter readings: end must be at least equal to start.', raw: t };
  }
  if (lower.includes('idempotency') || lower.includes('already been posted')) {
    return { friendly: 'This may have already been recorded. Refresh the page and check the status.', raw: t };
  }
  if (lower.includes('closed') && lower.includes('cycle')) {
    return { friendly: 'This crop cycle is closed — you cannot record against it.', raw: t };
  }

  return { friendly: t, raw: undefined };
}

export function formatOperatorError(err: unknown): OperatorFriendlyError {
  return toOperatorFriendlyMessage(extractApiErrorText(err));
}
