# Phase 1 + 3 Implementation Notes

## Overview

This phase implements the accounting schema and posting engine for Farm ERP v2 Accounting Core, following Architecture Spec v1 which is LOCKED and must be enforced.

## What Was Built

### 1. Database Schema Extensions

#### New Tables
- **crop_cycles**: Manages crop cycle periods with OPEN/CLOSED status
- **accounts**: Chart of Accounts (COA) with account types
- **posting_groups**: Groups accounting entries by source (one per source event)
- **allocation_rows**: Cost allocation tracking per posting group
- **ledger_entries**: Double-entry ledger entries (debit/credit)

#### Schema Updates
- **projects**: Added `crop_cycle_id` (NOT NULL after seeding) - each project belongs to exactly one crop cycle

#### Enums
- `crop_cycle_status`: OPEN, CLOSED
- `normal_balance`: DEBIT, CREDIT (optional, reserved for future use)

### 2. Database Triggers and Constraints

All invariants are enforced at the database level:

#### A) Immutability
- **Trigger**: `prevent_accounting_artifact_mutation()`
- **Enforcement**: BEFORE UPDATE OR DELETE on `posting_groups`, `allocation_rows`, `ledger_entries`
- **Action**: Raises exception preventing any modifications

#### B) Tenant Consistency
- **Triggers**: `validate_posting_group_tenant()`, `validate_allocation_row_tenant()`, `validate_ledger_entry_tenant()`
- **Enforcement**: BEFORE INSERT on accounting tables
- **Action**: Validates that tenant_id matches across related entities (project, posting_group, account)

#### C) Closed Cycle Lock
- **Trigger**: `validate_crop_cycle_open()`
- **Enforcement**: BEFORE INSERT on `posting_groups`
- **Action**: Blocks posting if project's crop cycle status is CLOSED

#### D) Posting Date Validation
- **Trigger**: `validate_posting_date_range()`
- **Enforcement**: BEFORE INSERT on `posting_groups`
- **Action**: Ensures posting_date is within crop cycle's start_date and end_date range

#### E) Double-Entry Balance
- **Trigger**: `check_posting_group_balance()`
- **Enforcement**: AFTER INSERT/UPDATE/DELETE on `ledger_entries` (DEFERRABLE INITIALLY DEFERRED)
- **Action**: Validates SUM(debit) == SUM(credit) for each posting group

### 3. Laravel API

#### New Endpoints
- `POST /api/daily-book-entries/{id}/post` - Post a DRAFT entry to accounting
- `GET /api/posting-groups/{id}` - Get posting group with relationships
- `GET /api/posting-groups/{id}/ledger-entries` - Get ledger entries for a posting group
- `GET /api/posting-groups/{id}/allocation-rows` - Get allocation rows for a posting group

#### Updated Endpoints
- `PATCH /api/daily-book-entries/{id}` - Now returns 409 if entry is POSTED
- `DELETE /api/daily-book-entries/{id}` - Now returns 409 if entry is POSTED

#### Services
- **PostingService**: Handles transactional posting with idempotency
  - Creates PostingGroup (or returns existing if already posted)
  - Creates AllocationRow
  - Creates LedgerEntries (balanced double-entry)
  - Updates DailyBookEntry status to POSTED

### 4. React UI

#### New Components
- **PostModal**: Modal for selecting posting date and posting an entry
- **PostingGroupDetailPage**: View posting group details, allocation rows, and ledger entries

#### Updated Components
- **DailyBookEntriesPage**: Added "Post" button for DRAFT entries
- **DailyBookEntryFormPage**: Shows read-only view for POSTED entries

### 5. TypeScript Types

Added types for:
- `CropCycle`
- `Account`
- `PostingGroup`
- `AllocationRow`
- `LedgerEntry`

Updated `Project` to include `crop_cycle_id`.

## Locked Rules Enforcement

### ✅ Rule 1: Explicit Transitions Only
- No implicit accounting side effects
- Accounting impact only via explicit POST action
- **Enforced**: PostingService is the only way to create accounting artifacts

### ✅ Rule 2: One Source Event => One PostingGroup
- Idempotency enforced by unique(tenant_id, source_type, source_id)
- Repeated POST returns existing posting group
- **Enforced**: Database unique constraint + PostingService checks for existing group

### ✅ Rule 3: Accounting Artifacts Are Immutable
- No UPDATE, no DELETE on posting_groups, allocation_rows, ledger_entries
- **Enforced**: Database triggers raise exceptions on UPDATE/DELETE

### ✅ Rule 4: Posting Date Semantics
- posting_date explicitly chosen by user at POST time
- Validated within Project's CropCycle date range
- If crop cycle is CLOSED, posting is blocked
- **Enforced**: Database triggers validate date range and cycle status

### ✅ Rule 5: Locking
- Enforced by CropCycle state (not date math) at DB level
- **Enforced**: Trigger checks crop_cycles.status = 'OPEN'

### ✅ Rule 6: Double-Entry Balance
- For each PostingGroup, SUM(debit) == SUM(credit)
- DB enforced with deferrable constraint trigger
- Transactional posting: if any step fails, nothing persists
- **Enforced**: Database trigger validates balance after all inserts

## Posting Logic (Phase 1 - Fixed Mapping)

For now, posting uses a fixed mapping (Phase 4 will make it rules-driven):

### Required Accounts (seeded per tenant)
- **CASH** (ASSET)
- **EXPENSES** (EXPENSE)
- **INCOME** (INCOME)

### Posting Rules
- **EXPENSE entries**:
  - Dr EXPENSES (amount)
  - Cr CASH (amount)
- **INCOME entries**:
  - Dr CASH (amount)
  - Cr INCOME (amount)

## Database Invariants Verification

### SQL Verification Queries

Run these queries in your Supabase Postgres database to verify invariants:

#### 1. Verify Immutability (should fail)
```sql
-- This should raise an exception
UPDATE posting_groups SET posting_date = '2024-01-20' WHERE id = (SELECT id FROM posting_groups LIMIT 1);
```

#### 2. Verify Tenant Consistency
```sql
-- All posting groups should have matching tenant_id with their projects
SELECT pg.id, pg.tenant_id, p.tenant_id as project_tenant_id
FROM posting_groups pg
JOIN projects p ON pg.project_id = p.id
WHERE pg.tenant_id != p.tenant_id;
-- Should return 0 rows
```

#### 3. Verify Closed Cycle Lock
```sql
-- Close a crop cycle
UPDATE crop_cycles SET status = 'CLOSED' WHERE id = (SELECT id FROM crop_cycles LIMIT 1);

-- Try to post (should fail via trigger)
-- This will be tested via API, but the trigger will block it
```

#### 4. Verify Posting Date Range
```sql
-- Check all posting dates are within their crop cycle ranges
SELECT pg.id, pg.posting_date, cc.start_date, cc.end_date
FROM posting_groups pg
JOIN projects p ON pg.project_id = p.id
JOIN crop_cycles cc ON p.crop_cycle_id = cc.id
WHERE pg.posting_date < cc.start_date OR pg.posting_date > cc.end_date;
-- Should return 0 rows
```

#### 5. Verify Double-Entry Balance
```sql
-- Check all posting groups are balanced
SELECT 
    pg.id,
    COALESCE(SUM(le.debit), 0) as total_debit,
    COALESCE(SUM(le.credit), 0) as total_credit,
    COALESCE(SUM(le.debit), 0) - COALESCE(SUM(le.credit), 0) as difference
FROM posting_groups pg
LEFT JOIN ledger_entries le ON pg.id = le.posting_group_id
GROUP BY pg.id
HAVING COALESCE(SUM(le.debit), 0) != COALESCE(SUM(le.credit), 0);
-- Should return 0 rows
```

#### 6. Verify Idempotency Constraint
```sql
-- Check no duplicate posting groups for same source
SELECT tenant_id, source_type, source_id, COUNT(*) as count
FROM posting_groups
GROUP BY tenant_id, source_type, source_id
HAVING COUNT(*) > 1;
-- Should return 0 rows
```

#### 7. Verify All Projects Have Crop Cycle
```sql
-- Check all projects have crop_cycle_id
SELECT id, name, crop_cycle_id
FROM projects
WHERE crop_cycle_id IS NULL;
-- Should return 0 rows (after seeding)
```

## Migration Instructions

### Supabase (Primary Source of Truth)

1. Run the complete `docs/migrations.sql` file in your Supabase SQL editor
2. This will create all tables, triggers, constraints, and seed data

### Laravel Migrations (Optional, for consistency)

If you want to keep Laravel migrations in sync:

```bash
cd apps/api
php artisan migrate
```

Note: The triggers are defined in `migrations.sql` and should be run in Supabase. Laravel migrations create the table structure but don't include triggers (they use DB::statement for basic constraints).

## Testing

### Run Tests

```bash
cd apps/api
php artisan test
```

### Test Coverage

- ✅ Posting creates exactly one posting group and ledger is balanced
- ✅ Repeated POST returns same posting group (idempotent)
- ✅ Cannot post if crop cycle CLOSED (DB exception surfaces as 422)
- ✅ Cannot post with posting_date outside crop cycle range (422)
- ✅ Cannot UPDATE/DELETE accounting artifacts (DB exception)
- ✅ Cannot PATCH/DELETE operational entry after it becomes POSTED (409)

## Seed Data

The migration creates:
- 1 OPEN crop cycle for demo tenant (2024-01-01 to 2024-12-31)
- All existing projects attached to the crop cycle
- 3 accounts: CASH, EXPENSES, INCOME

## Known Limitations (Phase 4 Will Address)

1. **Fixed Account Mapping**: Currently hardcoded to CASH/EXPENSES/INCOME. Phase 4 will introduce rule resolution.
2. **No Rule Versioning**: `rule_version`, `rule_hash`, `rule_snapshot_json` fields exist but are not populated.
3. **No Reversals**: Reversal functionality is reserved for future phases.

## Next Steps

Phase 4 will introduce:
- Rule resolver engine
- Dynamic account mapping
- Rule versioning and snapshots
- Reversal capabilities
