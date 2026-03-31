/**
 * Ensures liability amounts use a negative signed convention for net-position math.
 * If the backend sends a positive magnitude, treat it as a negative liability.
 */
export function normalizeLiability(value: number): number {
  return value > 0 ? -value : value;
}
