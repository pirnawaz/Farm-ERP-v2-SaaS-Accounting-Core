# Accounting Correction Guide

This guide describes how to identify and correct operational postings that incorrectly used the `PROFIT_DISTRIBUTION` or `PROFIT_DISTRIBUTION_CLEARING` account, and how to ensure P&L and Trial Balance align.

## Problem Summary

Operational postings (e.g. Inventory Issue in HARI_ONLY mode) previously posted to `PROFIT_DISTRIBUTION`, which is reserved for **settlement posting groups only**. That caused:

- Double-counting in P&L (e.g. Trial Balance shows PROJECT_REVENUE 345,000 but P&L shows 690,000)
- General Ledger showing INPUTS_EXPENSE credited and PROFIT_DISTRIBUTION used mid-cycle

## Architecture Rules

1. **Operational postings** must only recognize economic events (AR/Revenue, Expense/Inventory, Labour Expense/Wages Payable, etc.). They must **never** touch `PROFIT_DISTRIBUTION` or `PROFIT_DISTRIBUTION_CLEARING`.
2. **Settlement postings** are created only by an explicit SETTLE action and use `PROFIT_DISTRIBUTION_CLEARING` and party control accounts (`PARTY_CONTROL_HARI`, etc.).
3. **Party balances** use a single control account per party (e.g. `PARTY_CONTROL_HARI`); advances remain in `ADVANCE_HARI`.

## Automated Correction Command

The command **`php artisan accounting:fix-settlement-postings`** is fully automated. It:

1. Finds posting groups where `source_type` is operational (initially **INVENTORY_ISSUE** only) and ledger entries include `PROFIT_DISTRIBUTION` or `PROFIT_DISTRIBUTION_CLEARING`.
2. For each such posting group (that has not already been corrected):
   - Creates a **REVERSAL** PostingGroup (`source_type = ACCOUNTING_CORRECTION_REVERSAL`, `source_id = original_posting_group_id`) that exactly reverses all ledger entries (swap debit/credit) and copies allocation rows for audit.
   - Creates a **CORRECTED** PostingGroup (`source_type = ACCOUNTING_CORRECTION`, `source_id = original_posting_group_id`) with operational-only ledger: **Dr INPUTS_EXPENSE**, **Cr INVENTORY_INPUTS** (full issue value), and no `PARTY_CONTROL_*` or `PROFIT_DISTRIBUTION_*`.
   - Records the correction in the **`accounting_corrections`** table for idempotency and audit.

No existing artifacts are mutated; corrections are new posting groups only.

### CLI Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Only report what would be created; show would-be reversal and corrected PG IDs. No changes are persisted. |
| `--tenant=<uuid>` | Limit detection and correction to this tenant. |
| `--only-pg=<uuid>` | Fix only this posting group ID. |
| `--limit=N` | Cap the number of posting groups to fix in this run. |

### Examples

```bash
# Preview what would be corrected (no changes)
php artisan accounting:fix-settlement-postings --dry-run

# Limit to one tenant
php artisan accounting:fix-settlement-postings --dry-run --tenant=<tenant_uuid>

# Fix only a specific posting group
php artisan accounting:fix-settlement-postings --only-pg=<posting_group_uuid>

# Cap to 5 corrections per run
php artisan accounting:fix-settlement-postings --limit=5

# Execute corrections (idempotent: already-corrected PGs are skipped)
php artisan accounting:fix-settlement-postings
php artisan accounting:fix-settlement-postings --tenant=<tenant_uuid>
```

### Idempotency

- Each correction is recorded in **`accounting_corrections`** with `(tenant_id, original_posting_group_id)` unique.
- Running the command again skips any posting group that already has a row in `accounting_corrections` and prints `[skip] PG <id> already corrected`.

## Identifying Bad Postings Manually

### 1. Run the command (dry-run)

```bash
php artisan accounting:fix-settlement-postings --dry-run
```

Optionally limit to one tenant:

```bash
php artisan accounting:fix-settlement-postings --dry-run --tenant=<tenant_uuid>
```

### 2. SQL to list bad posting groups

```sql
SELECT pg.id, pg.tenant_id, pg.source_type, pg.source_id, pg.posting_date
FROM posting_groups pg
WHERE pg.source_type = 'INVENTORY_ISSUE'
AND EXISTS (
  SELECT 1 FROM ledger_entries le
  JOIN accounts a ON a.id = le.account_id
  WHERE le.posting_group_id = pg.id
  AND a.code IN ('PROFIT_DISTRIBUTION', 'PROFIT_DISTRIBUTION_CLEARING')
);
```

### 3. Inspect ledger entries for a posting group

```sql
SELECT le.id, a.code, a.name, le.debit_amount, le.credit_amount
FROM ledger_entries le
JOIN accounts a ON a.id = le.account_id
WHERE le.posting_group_id = '<posting_group_id>';
```

## Correction Strategy (Automated)

**Do not mutate existing posted artifacts.** The command:

1. Creates a **reversal** PostingGroup (`ACCOUNTING_CORRECTION_REVERSAL`) that negates all ledger entries of the bad posting group (swap debit/credit). Allocation rows are copied for audit.
2. Creates a **corrected** PostingGroup (`ACCOUNTING_CORRECTION`) with only:
   - **Dr INPUTS_EXPENSE** (full issue value)
   - **Cr INVENTORY_INPUTS** (full issue value)
   - No `PARTY_CONTROL_*`, no `PROFIT_DISTRIBUTION`, no `PROFIT_DISTRIBUTION_CLEARING`.

Traceability is stored in:

- **`accounting_corrections`** table: `original_posting_group_id`, `reversal_posting_group_id`, `corrected_posting_group_id`, `reason`, `correction_batch_run_at`.
- Posting groups: `correction_reason` and `source_type` / `source_id` as above.

## Verifying Corrections

1. **Trial Balance**  
   Run for the same date range as P&L. Sum of income accounts (credits − debits) and expense accounts (debits − credits) should match your expectations.

2. **Project P&L**  
   For the same period, Project P&L totals (income, expenses) should not double-count. They should align with the Trial Balance when aggregated by the same scope.

3. **Crop Cycle P&L**  
   Should equal the sum of Project P&Ls for projects in that crop cycle for the same period.

4. **General Ledger**  
   No operational posting should show `PROFIT_DISTRIBUTION` or `PROFIT_DISTRIBUTION_CLEARING`. Only settlement posting groups should use `PROFIT_DISTRIBUTION_CLEARING`.

## References

- Plan: Fix Accounting Model Bug (operational vs settlement separation, party control accounts, P&L fixes).
- `apps/api/app/Services/InventoryPostingService.php`: operational posting rules (no PROFIT_DISTRIBUTION).
- `apps/api/app/Services/SettlementService.php`: settlement uses PROFIT_DISTRIBUTION_CLEARING and PARTY_CONTROL_* only.
- `apps/api/app/Console/Commands/FixSettlementPostings.php`: automated correction command.
- `apps/api/app/Models/AccountingCorrection.php`: correction audit model.
