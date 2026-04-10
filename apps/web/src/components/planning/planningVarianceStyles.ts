/** How to colour variance rows: revenue & profit — higher is better; cost — lower spend vs plan is better. */
export function varianceClass(value: number, kind: 'revenue' | 'cost' | 'profit'): string {
  if (value === 0 || Object.is(value, -0)) {
    return 'text-gray-700';
  }
  if (kind === 'cost') {
    return value > 0 ? 'text-rose-600 font-semibold' : 'text-emerald-600 font-semibold';
  }
  return value > 0 ? 'text-emerald-600 font-semibold' : 'text-rose-600 font-semibold';
}
