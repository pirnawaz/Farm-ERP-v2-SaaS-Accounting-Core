/**
 * Date helpers for E2E (deterministic, ISO date strings).
 */

export function todayISO(): string {
  return new Date().toISOString().split('T')[0];
}

export function addDaysISO(dateIso: string, days: number): string {
  const d = new Date(dateIso + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().split('T')[0];
}
