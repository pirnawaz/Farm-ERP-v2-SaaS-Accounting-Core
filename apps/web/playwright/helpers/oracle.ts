/**
 * Ledger-based oracle: compute expected totals from ledger entries and compare to report output.
 */

export interface LedgerEntryLike {
  debit_amount?: number;
  credit_amount?: number;
  debit?: string | number;
  credit?: string | number;
}

function toNum(v: string | number | undefined): number {
  if (v === undefined || v === null) return 0;
  return typeof v === 'string' ? parseFloat(v) || 0 : v;
}

/**
 * From ledger entries: total debits, total credits, and per-account net (debit - credit).
 */
export function aggregateLedgerEntries(entries: LedgerEntryLike[]): {
  totalDebits: number;
  totalCredits: number;
  perAccountNet: Record<string, number>;
} {
  let totalDebits = 0;
  let totalCredits = 0;
  const perAccountNet: Record<string, number> = {};

  for (const e of entries) {
    const debit = toNum((e as any).debit_amount ?? e.debit);
    const credit = toNum((e as any).credit_amount ?? e.credit);
    totalDebits += debit;
    totalCredits += credit;
    const accountId = (e as any).account_id ?? (e as any).account?.id;
    if (accountId != null) {
      const key = String(accountId);
      perAccountNet[key] = (perAccountNet[key] ?? 0) + debit - credit;
    }
  }

  return { totalDebits, totalCredits, perAccountNet };
}

/**
 * Assert that total debits equal total credits for a set of ledger entries.
 */
export function assertLedgerBalanced(entries: LedgerEntryLike[]): void {
  const { totalDebits, totalCredits } = aggregateLedgerEntries(entries);
  if (Math.abs(totalDebits - totalCredits) > 0.001) {
    throw new Error(
      `Ledger not balanced: totalDebits=${totalDebits} totalCredits=${totalCredits}`
    );
  }
}

export interface TrialBalanceRowLike {
  account_id: string;
  total_debit?: string | number;
  total_credit?: string | number;
  net?: string | number;
}

/**
 * Assert trial balance report: sum of debits == sum of credits.
 */
export function assertTrialBalanceBalanced(rows: TrialBalanceRowLike[]): void {
  let sumDebit = 0;
  let sumCredit = 0;
  for (const r of rows) {
    sumDebit += toNum(r.total_debit);
    sumCredit += toNum(r.total_credit);
  }
  if (Math.abs(sumDebit - sumCredit) > 0.001) {
    throw new Error(
      `Trial balance not balanced: sumDebit=${sumDebit} sumCredit=${sumCredit}`
    );
  }
}

/**
 * Assert trial balance report matches oracle for (optionally) only accounts touched by the posting.
 * @param report - trial balance rows from report API
 * @param oracle - per-account net from aggregateLedgerEntries
 * @param accountsTouchedOnly - if true, only assert for accounts present in oracle; if false, assert all report rows
 */
export function assertReportMatchesOracle(
  report: TrialBalanceRowLike[],
  oracle: Record<string, number>,
  accountsTouchedOnly = true
): void {
  for (const row of report) {
    const accountId = String(row.account_id);
    const expected = oracle[accountId];
    if (accountsTouchedOnly && expected === undefined) continue;
    if (expected === undefined) {
      throw new Error(`Account ${accountId}: in report but not in oracle`);
    }
    const reported = toNum(row.net);
    if (Math.abs(expected - reported) > 0.001) {
      throw new Error(
        `Account ${accountId}: oracle net=${expected} report net=${reported}`
      );
    }
  }
}
