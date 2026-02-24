/**
 * Extract a user-friendly message from an API error.
 */
export function getApiErrorMessage(error: unknown, fallback = 'Something went wrong'): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const res = (error as { response?: { data?: { message?: string; error?: string } } }).response?.data;
    if (res?.message) return res.message;
    if (res?.error) return res.error;
  }
  if (error instanceof Error) return error.message;
  return fallback;
}
