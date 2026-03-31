import { normalizeLiability } from './normalizeLiability';

/** Net position using a signed model: sum of balances (liabilities normalized negative). */
export function computeNetPositionSigned(
  cash: number,
  bank: number,
  receivables: number,
  payables: number,
  labourOwed: number
): number {
  return cash + bank + receivables + normalizeLiability(payables) + normalizeLiability(labourOwed);
}
