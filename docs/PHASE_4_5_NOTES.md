# Phase 4 + 5 Implementation Notes

## Overview

Phase 4 and 5 implement rule resolution with versioned snapshots and reversal/correction workflows. This document describes the implementation details, design decisions, and verification procedures.

## Phase 4: Rule Resolver Contract + Versioned Snapshots

### Architecture

The rule resolution system provides a deterministic way to map operational events (DailyBookEntry) to accounting artifacts (AllocationRows and LedgerEntries) based on configuration rules.

### Key Components

1. **DailyBookAccountMapping Table**
   - Stores tenant-scoped account mapping rules
   - Versioned with effective date ranges
   - Maps entry types (EXPENSE/INCOME) to debit/credit accounts

2. **RuleResolver Interface**
   - Contract: `resolveDailyBookEntry(tenantId, entryId, postingDate)`
   - Returns: `RuleResolutionResult` with rule version, hash, snapshot, and allocation/ledger plans

3. **DailyBookEntryRuleResolver**
   - Implementation that selects mapping based on `posting_date` (not `event_date`)
   - Builds canonical snapshot for audit trail
   - Computes SHA256 hash of snapshot

### Mapping Selection Logic

The resolver selects the mapping using the following rules:

1. Find all mappings where:
   - `tenant_id` matches
   - `effective_from <= posting_date`
   - `effective_to IS NULL OR effective_to >= posting_date`

2. If multiple mappings match, select the one with the **greatest `effective_from`** (most recent)

3. This ensures deterministic selection based on posting date

**Example:**
```
Mapping v1: effective_from='2024-01-01', effective_to='2024-06-30'
Mapping v2: effective_from='2024-07-01', effective_to=NULL

Posting with date '2024-06-15' -> uses v1
Posting with date '2024-07-15' -> uses v2
```

### Snapshot Format

The canonical snapshot is a JSON structure with stable key ordering:

```json
{
  "source_type": "DAILY_BOOK_ENTRY",
  "source_id": "<uuid>",
  "posting_date": "YYYY-MM-DD",
  "mapping": {
    "version": "v1",
    "effective_from": "YYYY-MM-DD",
    "effective_to": null,
    "expense_debit_account_code": "EXPENSES",
    "expense_credit_account_code": "CASH",
    "income_debit_account_code": "CASH",
    "income_credit_account_code": "INCOME"
  }
}
```

**Hash Computation:**
```php
$snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ruleHash = hash('sha256', $snapshotJson);
```

The hash is stored in `allocation_rows.rule_hash` and provides an immutable audit trail.

### Immutability Guarantees

1. **Snapshot Persistence**: Rule snapshot is persisted at POST time and never recalculated
2. **Hash Verification**: The hash can be recomputed from the snapshot to verify integrity
3. **No Updates**: AllocationRows, PostingGroups, and LedgerEntries are immutable (DB triggers enforce this)

### PostingService Integration

The `PostingService` now:
1. Calls `RuleResolver::resolveDailyBookEntry()` with `posting_date`
2. Persists `rule_version`, `rule_hash`, and `rule_snapshot_json` in AllocationRow
3. Creates LedgerEntries from the resolution result
4. Maintains idempotency (repeated POST returns existing PostingGroup)

## Phase 5: Reversals / Corrections

### Design Principles

1. **Historical Truth Preserved**: Original PostingGroup is never modified
2. **New PostingGroup Only**: Corrections create a new PostingGroup with `source_type='REVERSAL'`
3. **Exact Negation**: Reversal ledger entries exactly negate original entries (swap debit/credit)
4. **Idempotency**: Multiple reversal calls with same `posting_date` return the same reversal

### Reversal Workflow

**Endpoint:** `POST /api/posting-groups/{id}/reverse`

**Request Body:**
```json
{
  "posting_date": "YYYY-MM-DD",
  "reason": "Correction: incorrect amount"
}
```

**Process:**
1. Load original PostingGroup (tenant-scoped)
2. Validate: original must not be a reversal itself
3. Check idempotency: if reversal exists for same `posting_date`, return it
4. Create new PostingGroup:
   - `source_type = 'REVERSAL'`
   - `source_id = original_posting_group.id`
   - `reversal_of_posting_group_id = original.id`
   - `correction_reason = reason`
5. Create AllocationRows:
   - Same `project_id`, `cost_type`, `amount`, `currency_code`
   - Rule snapshot includes `reversal_of`, `original_rule_hash`, `reversal_reason`
6. Create LedgerEntries:
   - For each original entry: swap debit and credit
   - Same `account_id` and `currency_code`
7. DB triggers validate:
   - Crop cycle is OPEN
   - Posting date is within crop cycle range
   - Double-entry balance

### Reversal AllocationRow Snapshot

Reversal allocation rows include additional metadata in the snapshot:

```json
{
  "source_type": "DAILY_BOOK_ENTRY",
  "source_id": "<original_entry_id>",
  "posting_date": "YYYY-MM-DD",
  "mapping": { ... },
  "reversal_of": "<original_posting_group_id>",
  "original_rule_hash": "<original_hash>",
  "reversal_reason": "Correction: incorrect amount"
}
```

### Constraints

1. **Unique Constraint**: `(tenant_id, reversal_of_posting_group_id, posting_date)` prevents duplicate reversals on same date
2. **Self-Reference Prevention**: `reversal_of_posting_group_id != id` (CHECK constraint)
3. **No Reversal of Reversal**: Application logic prevents reversing a reversal
4. **Closed Cycle Lock**: DB triggers prevent reversals into closed crop cycles

### Reversal Relationships

- `PostingGroup.reversalOf()`: BelongsTo relationship to original posting group
- `PostingGroup.reversals()`: HasMany relationship to all reversals

**Endpoint:** `GET /api/posting-groups/{id}/reversals` - Lists all reversals of a posting group

## Database Schema Changes

### posting_groups Table

**New Columns:**
- `reversal_of_posting_group_id UUID NULL` - References original posting group
- `correction_reason TEXT NULL` - User-provided reason for reversal

**New Constraints:**
- `posting_groups_no_self_reversal`: `reversal_of_posting_group_id != id`
- Unique: `(tenant_id, reversal_of_posting_group_id, posting_date)` (partial index, NULL values excluded)

**New Index:**
- `idx_posting_groups_reversal`: `(tenant_id, reversal_of_posting_group_id)`

### daily_book_account_mappings Table

**Columns:**
- `id UUID PRIMARY KEY`
- `tenant_id UUID NOT NULL`
- `version TEXT NOT NULL` - e.g., 'v1', 'v2'
- `effective_from DATE NOT NULL`
- `effective_to DATE NULL` - NULL means open-ended
- `expense_debit_account_id UUID NOT NULL`
- `expense_credit_account_id UUID NOT NULL`
- `income_debit_account_id UUID NOT NULL`
- `income_credit_account_id UUID NOT NULL`

**Constraints:**
- Unique: `(tenant_id, version, effective_from)`
- Date range: `effective_to IS NULL OR effective_to >= effective_from`
- Tenant consistency: DB trigger ensures all account_ids belong to same tenant

**Indexes:**
- `(tenant_id, effective_from, effective_to)` - For efficient mapping selection

## Verification SQL

### Verify Rule Resolution Uses Posting Date

```sql
-- Create two mappings
INSERT INTO daily_book_account_mappings (
    tenant_id, version, effective_from, effective_to,
    expense_debit_account_id, expense_credit_account_id,
    income_debit_account_id, income_credit_account_id
) VALUES
    ('<tenant_id>', 'v1', '2024-01-01', '2024-06-30', ...),
    ('<tenant_id>', 'v2', '2024-07-01', NULL, ...);

-- Post entry with earlier date -> should use v1
-- Post entry with later date -> should use v2
-- Verify allocation_rows.rule_version matches expected version
```

### Verify Snapshot Immutability

```sql
-- Post entry using mapping v1
-- Note allocation_rows.rule_hash and rule_snapshot_json

-- Add new mapping v2 with earlier effective_from
INSERT INTO daily_book_account_mappings (...);

-- Verify original allocation_rows unchanged:
SELECT rule_version, rule_hash, rule_snapshot_json
FROM allocation_rows
WHERE id = '<original_allocation_row_id>';
-- Should still show v1 and original hash
```

### Verify Reversal Correctness

```sql
-- Get original posting group ledger entries
SELECT account_id, debit, credit
FROM ledger_entries
WHERE posting_group_id = '<original_posting_group_id>'
ORDER BY account_id;

-- Get reversal posting group ledger entries
SELECT account_id, debit, credit
FROM ledger_entries
WHERE posting_group_id = '<reversal_posting_group_id>'
ORDER BY account_id;

-- Verify: reversal.debit = original.credit AND reversal.credit = original.debit
-- Verify: reversal entries balance (SUM(debit) = SUM(credit))
```

### Verify Reversal Idempotency

```sql
-- Attempt to create reversal twice with same posting_date
-- Should only have one reversal:
SELECT COUNT(*)
FROM posting_groups
WHERE reversal_of_posting_group_id = '<original_id>'
  AND posting_date = '<same_date>';
-- Should return 1
```

### Verify Closed Cycle Lock

```sql
-- Close crop cycle
UPDATE crop_cycles SET status = 'CLOSED' WHERE id = '<cycle_id>';

-- Attempt to create reversal -> should fail with DB trigger error
```

### Verify Immutability

```sql
-- Attempt to update posting_group -> should fail
UPDATE posting_groups SET posting_date = '2024-01-20' WHERE id = '<id>';
-- Error: "Accounting artifacts are immutable. Cannot UPDATE posting_groups"

-- Attempt to delete allocation_row -> should fail
DELETE FROM allocation_rows WHERE id = '<id>';
-- Error: "Accounting artifacts are immutable. Cannot DELETE allocation_rows"
```

## Testing

Comprehensive tests cover:

1. **Rule Resolution**
   - Uses `posting_date` (not `event_date`)
   - Selects correct mapping version based on effective dates
   - Handles overlapping date ranges correctly

2. **Snapshot Immutability**
   - Snapshots are frozen at POST time
   - Changing mappings doesn't affect existing snapshots
   - Hash can be recomputed from snapshot

3. **Reversal Correctness**
   - Reversal ledger entries exactly negate original
   - Reversal entries balance
   - Reversal metadata stored correctly

4. **Reversal Idempotency**
   - Multiple calls with same `posting_date` return same reversal
   - Unique constraint prevents duplicates

5. **Closed Cycle Lock**
   - Reversals blocked for closed cycles
   - DB triggers enforce this

6. **Immutability**
   - Cannot update/delete accounting artifacts
   - DB triggers enforce this

## UI Updates

### PostingGroupDetailPage

1. **Rule Snapshot Display**
   - Shows `rule_version` and `rule_hash` per allocation row
   - Collapsible view of `rule_snapshot_json` (click hash to expand)

2. **Reverse Button**
   - Visible only if `source_type != 'REVERSAL'`
   - Opens modal with:
     - Posting date picker
     - Reason textarea
   - On success, redirects to reversal posting group detail page

3. **Reversal Information**
   - If `reversal_of_posting_group_id` is set, shows:
     - Link to original posting group
     - Correction reason

## Migration Notes

1. **Backward Compatibility**: Existing PostingGroups without rule snapshots will have NULL `rule_version`, `rule_hash`, and `rule_snapshot_json`. This is acceptable for historical data.

2. **Seed Data**: Default mapping (v1) is seeded for demo tenant with effective_from matching crop cycle start_date.

3. **Laravel Migrations**: Mirror all schema changes in Laravel migrations for consistency.

## Future Considerations

1. **Multi-line Allocations**: Current implementation supports 1 allocation row per DailyBookEntry. Future phases may add multi-line support.

2. **Reporting**: Phase 6 will use rule snapshots for audit trails and reporting.

3. **Mapping UI**: Future phase may add UI for managing account mappings.

4. **Rule Versioning**: Consider adding migration path for rule version changes.
