export function getMoneyColorClass(value: number): string {
  return value < 0 ? 'text-red-600' : '';
}
