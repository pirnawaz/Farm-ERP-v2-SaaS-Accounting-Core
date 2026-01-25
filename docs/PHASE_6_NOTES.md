# Phase 6: Reporting & Restatement Semantics (READ-ONLY)

## Overview

Phase 6 implements read-only reporting endpoints that follow strict accounting semantics:
- Reports use `posting_date` for all time filtering (not operational `event_date`)
- Reports read from immutable `ledger_entries` (ledger is the financial truth)
- Reversals are naturally included in sums (do not "delete history")
- No edits/recalc of accounting artifacts - reporting is read-only

## Database Views

### v_ledger_lines

Normalized ledger lines with joined context for reporting. This view provides a denormalized view of ledger entries with account and posting group information.

**Columns:**
- `tenant_id` - Tenant identifier
- `posting_group_id` - Posting group identifier
- `posting_date` - Date used for reporting (NOT event_date)
- `project_id` - Project identifier
- `source_type` - Source type (e.g., 'DAILY_BOOK_ENTRY')
- `source_id` - Source identifier
- `reversal_of_posting_group_id` - If this is a reversal, the ID of the original posting group
- `correction_reason` - Reason for reversal/correction
- `ledger_entry_id` - Ledger entry identifier
- `account_id` - Account identifier
- `account_code` - Account code
- `account_name` - Account name
- `account_type` - Account type (ASSET, LIABILITY, EQUITY, INCOME, EXPENSE)
- `currency_code` - Currency code
- `debit` - Debit amount
- `credit` - Credit amount
- `net` - Net amount (debit - credit)

**Usage:**
```sql
SELECT * FROM v_ledger_lines
WHERE tenant_id = :tenant_id
  AND posting_date BETWEEN :from AND :to;
```

### v_trial_balance

Trial balance aggregated by account. **Note:** This view does NOT include date filtering - the API must apply `WHERE posting_date BETWEEN :from AND :to` when querying.

**Columns:**
- `tenant_id` - Tenant identifier
- `account_id` - Account identifier
- `account_code` - Account code
- `account_name` - Account name
- `account_type` - Account type
- `currency_code` - Currency code
- `total_debit` - Sum of debits
- `total_credit` - Sum of credits
- `net` - Net balance (total_debit - total_credit)

**Usage:**
```sql
SELECT * FROM v_trial_balance
WHERE tenant_id = :tenant_id
  AND account_id IN (
    SELECT DISTINCT account_id FROM v_ledger_lines
    WHERE tenant_id = :tenant_id
      AND posting_date BETWEEN :from AND :to
  );
```

## Report Definitions

### 1. Trial Balance

**Endpoint:** `GET /api/reports/trial-balance`

**Parameters:**
- `from` (required): Start date (YYYY-MM-DD)
- `to` (required): End date (YYYY-MM-DD)
- `project_id` (optional): Filter by project
- `currency_code` (optional): Filter by currency

**Returns:** Array of account rows with:
- `account_code`, `account_name`, `account_type`
- `total_debit`, `total_credit`, `net`

**Formula:**
- Sum all debits and credits for each account where `posting_date BETWEEN :from AND :to`
- Net = total_debit - total_credit

**Sample SQL Verification:**
```sql
SELECT
  account_id,
  account_code,
  account_name,
  account_type,
  currency_code,
  SUM(debit) AS total_debit,
  SUM(credit) AS total_credit,
  SUM(debit - credit) AS net
FROM v_ledger_lines
WHERE tenant_id = '00000000-0000-0000-0000-000000000001'
  AND posting_date BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY account_id, account_code, account_name, account_type, currency_code
ORDER BY account_code;
```

### 2. General Ledger

**Endpoint:** `GET /api/reports/general-ledger`

**Parameters:**
- `from` (required): Start date (YYYY-MM-DD)
- `to` (required): End date (YYYY-MM-DD)
- `account_id` (optional): Filter by account
- `project_id` (optional): Filter by project
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Items per page (default: 50, max: 1000)

**Returns:** Paginated response with:
- `data`: Array of ledger line items
- `pagination`: Pagination metadata

**Each line item includes:**
- `posting_date`, `posting_group_id`, `source_type`, `source_id`
- `reversal_of_posting_group_id` (null if not a reversal)
- `debit`, `credit`, `net`
- Account information

**Sorting:** By `posting_date ASC`, then `ledger_entry_id ASC`

**Sample SQL Verification:**
```sql
SELECT
  posting_date,
  posting_group_id,
  account_code,
  account_name,
  debit,
  credit,
  net,
  reversal_of_posting_group_id
FROM v_ledger_lines
WHERE tenant_id = '00000000-0000-0000-0000-000000000001'
  AND posting_date BETWEEN '2024-01-01' AND '2024-01-31'
ORDER BY posting_date ASC, ledger_entry_id ASC
LIMIT 50 OFFSET 0;
```

### 3. Project P&L

**Endpoint:** `GET /api/reports/project-pl`

**Parameters:**
- `from` (required): Start date (YYYY-MM-DD)
- `to` (required): End date (YYYY-MM-DD)
- `project_id` (optional): Filter by project (if omitted, returns all projects)

**Returns:** Array of project rows with:
- `project_id`, `currency_code`
- `income`, `expenses`, `net_profit`

**Formulas:**
- **Income accounts:** Contribute positive profit: `SUM(credit - debit)`
- **Expense accounts:** Contribute negative profit: `SUM(debit - credit)`
- **Net Profit:** `SUM(CASE WHEN account_type='INCOME' THEN (credit - debit) WHEN account_type='EXPENSE' THEN -(debit - credit) ELSE 0 END)`

**Sample SQL Verification:**
```sql
SELECT
  project_id,
  currency_code,
  SUM(CASE WHEN account_type = 'INCOME' THEN (credit - debit) ELSE 0 END) AS income,
  SUM(CASE WHEN account_type = 'EXPENSE' THEN (debit - credit) ELSE 0 END) AS expenses,
  SUM(
    CASE 
      WHEN account_type = 'INCOME' THEN (credit - debit)
      WHEN account_type = 'EXPENSE' THEN -(debit - credit)
      ELSE 0 
    END
  ) AS net_profit
FROM v_ledger_lines
WHERE tenant_id = '00000000-0000-0000-0000-000000000001'
  AND posting_date BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY project_id, currency_code;
```

### 4. Crop Cycle P&L

**Endpoint:** `GET /api/reports/crop-cycle-pl`

**Parameters:**
- `from` (required): Start date (YYYY-MM-DD)
- `to` (required): End date (YYYY-MM-DD)
- `crop_cycle_id` (optional): Filter by crop cycle (if omitted, returns all crop cycles)

**Returns:** Array of crop cycle rows with:
- `crop_cycle_id`, `crop_cycle_name`, `currency_code`
- `income`, `expenses`, `net_profit`

**Formulas:** Same as Project P&L, but grouped by crop cycle (via projects -> crop_cycles join)

**Sample SQL Verification:**
```sql
SELECT
  cc.id AS crop_cycle_id,
  cc.name AS crop_cycle_name,
  v.currency_code,
  SUM(CASE WHEN v.account_type = 'INCOME' THEN (v.credit - v.debit) ELSE 0 END) AS income,
  SUM(CASE WHEN v.account_type = 'EXPENSE' THEN (v.debit - v.credit) ELSE 0 END) AS expenses,
  SUM(
    CASE 
      WHEN v.account_type = 'INCOME' THEN (v.credit - v.debit)
      WHEN v.account_type = 'EXPENSE' THEN -(v.debit - v.credit)
      ELSE 0 
    END
  ) AS net_profit
FROM v_ledger_lines v
JOIN projects p ON p.id = v.project_id
JOIN crop_cycles cc ON cc.id = p.crop_cycle_id
WHERE v.tenant_id = '00000000-0000-0000-0000-000000000001'
  AND v.posting_date BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY cc.id, cc.name, v.currency_code;
```

### 5. Account Balances (As-Of Date)

**Endpoint:** `GET /api/reports/account-balances`

**Parameters:**
- `as_of` (required): As-of date (YYYY-MM-DD)
- `project_id` (optional): Filter by project

**Returns:** Array of account rows with:
- `account_code`, `account_name`, `account_type`
- `debits`, `credits`, `balance`

**Formula:**
- Sum all debits and credits for each account where `posting_date <= :as_of`
- Balance = debits - credits

**Sample SQL Verification:**
```sql
SELECT
  account_id,
  account_code,
  account_name,
  account_type,
  currency_code,
  SUM(debit) AS debits,
  SUM(credit) AS credits,
  SUM(debit - credit) AS balance
FROM v_ledger_lines
WHERE tenant_id = '00000000-0000-0000-0000-000000000001'
  AND posting_date <= '2024-01-31'
GROUP BY account_id, account_code, account_name, account_type, currency_code
ORDER BY account_code;
```

## Key Semantics

### 1. posting_date vs event_date

**CRITICAL:** All reports use `posting_date` from `posting_groups`, NOT `event_date` from `daily_book_entries`.

**Example:**
- Daily book entry created with `event_date = '2024-01-10'`
- Posted with `posting_date = '2024-01-15'`
- Trial balance for `from=2024-01-15, to=2024-01-15` **WILL** include this entry
- Trial balance for `from=2024-01-10, to=2024-01-14` **WILL NOT** include this entry

**Rationale:** Financial reporting must reflect when transactions were actually posted to the ledger, not when operational events occurred.

### 2. Reversals Net Correctly

Reversals create new posting groups with negating ledger entries. When summing over a date range that includes both the original and reversal:

- Original posting: Expense 100, Cash -100
- Reversal posting: Expense -100, Cash 100
- Net result: Expense 0, Cash 0

**This is correct behavior** - reversals do not "delete history", they create offsetting entries that naturally net to zero when both are included in the date range.

### 3. Tenant Isolation

All reports are tenant-scoped via `X-Tenant-Id` header. The API enforces:
- All queries filter by `tenant_id = :tenant_id`
- Views include `tenant_id` column for filtering
- No cross-tenant data leakage

### 4. Immutability

Reports are **read-only**. They:
- Never modify `posting_groups`, `allocation_rows`, or `ledger_entries`
- Never recalculate rule snapshots
- Never update accounting artifacts

## Income/Expense Sign Conventions

### Income Accounts
- **Normal balance:** CREDIT
- **P&L contribution:** `credit - debit` (positive = profit)
- **Example:** Income of 500 → Credit 500, Debit 0 → Profit = +500

### Expense Accounts
- **Normal balance:** DEBIT
- **P&L contribution:** `debit - credit` (positive = expense, negative = profit)
- **Example:** Expense of 100 → Debit 100, Credit 0 → Profit = -100

### Net Profit Formula
```
net_profit = SUM(
  CASE 
    WHEN account_type = 'INCOME' THEN (credit - debit)
    WHEN account_type = 'EXPENSE' THEN -(debit - credit)
    ELSE 0 
  END
)
```

This ensures:
- Income increases profit (positive contribution)
- Expenses decrease profit (negative contribution)

## Testing

Feature tests in `apps/api/tests/Feature/ReportingTest.php` verify:

1. **posting_date filtering:** Reports only include entries within the posting_date range, regardless of event_date
2. **Reversal netting:** Reversals correctly offset original entries when both are in the date range
3. **Tenant isolation:** Reports scoped by X-Tenant-Id never include other tenant rows
4. **Pagination:** General ledger endpoint paginates correctly with stable ordering

## Implementation Notes

### Laravel API
- `ReportController` uses `DB::select()` with raw SQL for clarity and performance
- All queries explicitly filter by `tenant_id` from request attributes
- Date filtering always uses `posting_date`, never `event_date`
- Pagination uses standard offset/limit with total count

### React UI
- All report pages include filters (date ranges, projects, accounts, etc.)
- CSV export implemented client-side using `exportToCSV` utility
- General ledger shows "REVERSAL" tags when `reversal_of_posting_group_id` is not null
- Links to posting group detail pages for drill-down

### TypeScript Types
- All report types defined in `packages/shared/src/types.ts`
- API client methods in `packages/shared/src/api-client.ts` with proper typing
- Query string building handles optional parameters correctly

## Future Enhancements (Not in Phase 6)

- Export to Excel format
- Scheduled report generation
- Report templates/customization
- Multi-currency consolidation
- Comparative reporting (period-over-period)
- Drill-down from summary to detail views
