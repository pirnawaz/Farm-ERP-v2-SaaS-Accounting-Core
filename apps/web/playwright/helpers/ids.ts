/**
 * Utility to extract IDs from URL or UI.
 */

/**
 * Extract UUID from current page URL (e.g. /app/transactions/abc-123 -> abc-123).
 */
export function getIdFromUrl(url: string): string | null {
  const match = url.match(
    /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i
  );
  return match ? match[0] : null;
}

/**
 * Extract last path segment as ID (e.g. /app/transactions/abc-123 -> abc-123).
 */
export function getLastSegmentId(pathname: string): string | null {
  const segments = pathname.replace(/\/$/, '').split('/');
  const last = segments[segments.length - 1];
  return last && last !== 'new' ? last : null;
}
