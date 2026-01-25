-- Farm ERP v2 - Operational Tables Migration
-- Run this in your Supabase Postgres database

-- Tenants table
CREATE TABLE IF NOT EXISTS tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Projects table
CREATE TABLE IF NOT EXISTS projects (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    name TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Daily Book Entries table
CREATE TABLE IF NOT EXISTS daily_book_entries (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    project_id UUID NOT NULL REFERENCES projects(id),
    type TEXT NOT NULL CHECK (type IN ('EXPENSE', 'INCOME')),
    status TEXT NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'POSTED', 'VOID')),
    event_date DATE NOT NULL,
    description TEXT NOT NULL,
    gross_amount NUMERIC(14,2) NOT NULL CHECK (gross_amount >= 0),
    currency_code CHAR(3) NOT NULL DEFAULT 'GBP',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_daily_book_entries_tenant_created 
    ON daily_book_entries(tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_daily_book_entries_project 
    ON daily_book_entries(project_id);

-- Seed data
INSERT INTO tenants (id, name) VALUES 
    ('00000000-0000-0000-0000-000000000001', 'Demo Farm')
ON CONFLICT (id) DO NOTHING;

INSERT INTO projects (id, tenant_id, name) VALUES 
    ('00000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000001', 'Wheat Field A'),
    ('00000000-0000-0000-0000-000000000011', '00000000-0000-0000-0000-000000000001', 'Corn Field B')
ON CONFLICT (id) DO NOTHING;

INSERT INTO daily_book_entries (id, tenant_id, project_id, type, status, event_date, description, gross_amount, currency_code) VALUES 
    ('00000000-0000-0000-0000-000000000100', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', 'EXPENSE', 'DRAFT', '2024-01-15', 'Seed purchase', 1250.00, 'GBP'),
    ('00000000-0000-0000-0000-000000000101', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000010', 'EXPENSE', 'DRAFT', '2024-01-20', 'Fertilizer', 850.50, 'GBP'),
    ('00000000-0000-0000-0000-000000000102', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000011', 'INCOME', 'DRAFT', '2024-02-01', 'Harvest sale', 5000.00, 'GBP')
ON CONFLICT (id) DO NOTHING;

-- ============================================================================
-- PHASE 1 + 3: Accounting Schema
-- ============================================================================

-- Create enums
DO $$ BEGIN
    CREATE TYPE crop_cycle_status AS ENUM ('OPEN', 'CLOSED');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

DO $$ BEGIN
    CREATE TYPE normal_balance AS ENUM ('DEBIT', 'CREDIT');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

-- Crop Cycles table
CREATE TABLE IF NOT EXISTS crop_cycles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    name TEXT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status crop_cycle_status NOT NULL DEFAULT 'OPEN',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT crop_cycles_date_range CHECK (start_date <= end_date)
);

CREATE INDEX IF NOT EXISTS idx_crop_cycles_tenant ON crop_cycles(tenant_id);
CREATE INDEX IF NOT EXISTS idx_crop_cycles_status ON crop_cycles(status);

-- Update projects table: add crop_cycle_id
ALTER TABLE projects 
    ADD COLUMN IF NOT EXISTS crop_cycle_id UUID REFERENCES crop_cycles(id);

-- Make crop_cycle_id NOT NULL after seeding (will be handled in seed section)
-- For existing projects, we'll assign them to a default crop cycle in seed data

-- Accounts table (Chart of Accounts)
CREATE TABLE IF NOT EXISTS accounts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(tenant_id, code)
);

CREATE INDEX IF NOT EXISTS idx_accounts_tenant ON accounts(tenant_id);
CREATE INDEX IF NOT EXISTS idx_accounts_tenant_code ON accounts(tenant_id, code);

-- Posting Groups table
CREATE TABLE IF NOT EXISTS posting_groups (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    project_id UUID NOT NULL REFERENCES projects(id),
    source_type TEXT NOT NULL,
    source_id UUID NOT NULL,
    posting_date DATE NOT NULL,
    reversal_of_posting_group_id UUID NULL REFERENCES posting_groups(id),
    correction_reason TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(tenant_id, source_type, source_id),
    CONSTRAINT posting_groups_no_self_reversal CHECK (reversal_of_posting_group_id != id),
    UNIQUE(tenant_id, reversal_of_posting_group_id, posting_date)
);

CREATE INDEX IF NOT EXISTS idx_posting_groups_tenant ON posting_groups(tenant_id);
CREATE INDEX IF NOT EXISTS idx_posting_groups_project ON posting_groups(project_id);
CREATE INDEX IF NOT EXISTS idx_posting_groups_source ON posting_groups(tenant_id, source_type, source_id);
CREATE INDEX IF NOT EXISTS idx_posting_groups_posting_date ON posting_groups(posting_date);
CREATE INDEX IF NOT EXISTS idx_posting_groups_reversal ON posting_groups(tenant_id, reversal_of_posting_group_id);

-- Allocation Rows table
CREATE TABLE IF NOT EXISTS allocation_rows (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    posting_group_id UUID NOT NULL REFERENCES posting_groups(id),
    project_id UUID NOT NULL REFERENCES projects(id),
    cost_type TEXT NOT NULL,
    amount NUMERIC(14,2) NOT NULL CHECK(amount >= 0),
    currency_code CHAR(3) NOT NULL DEFAULT 'GBP',
    rule_version TEXT,
    rule_hash TEXT,
    rule_snapshot_json JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_allocation_rows_tenant ON allocation_rows(tenant_id);
CREATE INDEX IF NOT EXISTS idx_allocation_rows_posting_group ON allocation_rows(posting_group_id);
CREATE INDEX IF NOT EXISTS idx_allocation_rows_project ON allocation_rows(project_id);

-- Ledger Entries table
CREATE TABLE IF NOT EXISTS ledger_entries (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    posting_group_id UUID NOT NULL REFERENCES posting_groups(id),
    account_id UUID NOT NULL REFERENCES accounts(id),
    debit NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK(debit >= 0),
    credit NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK(credit >= 0),
    currency_code CHAR(3) NOT NULL DEFAULT 'GBP',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT ledger_entries_debit_credit_exclusive CHECK (NOT (debit > 0 AND credit > 0)),
    CONSTRAINT ledger_entries_debit_credit_required CHECK ((debit > 0) OR (credit > 0))
);

CREATE INDEX IF NOT EXISTS idx_ledger_entries_tenant ON ledger_entries(tenant_id);
CREATE INDEX IF NOT EXISTS idx_ledger_entries_posting_group ON ledger_entries(tenant_id, posting_group_id);
CREATE INDEX IF NOT EXISTS idx_ledger_entries_account ON ledger_entries(tenant_id, account_id);

-- ============================================================================
-- TRIGGERS AND CONSTRAINTS
-- ============================================================================

-- A) Immutability triggers: Prevent UPDATE/DELETE on accounting artifacts
CREATE OR REPLACE FUNCTION prevent_accounting_artifact_mutation()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'Accounting artifacts are immutable. Cannot % %', TG_OP, TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS posting_groups_immutability ON posting_groups;
CREATE TRIGGER posting_groups_immutability
    BEFORE UPDATE OR DELETE ON posting_groups
    FOR EACH ROW
    EXECUTE FUNCTION prevent_accounting_artifact_mutation();

DROP TRIGGER IF EXISTS allocation_rows_immutability ON allocation_rows;
CREATE TRIGGER allocation_rows_immutability
    BEFORE UPDATE OR DELETE ON allocation_rows
    FOR EACH ROW
    EXECUTE FUNCTION prevent_accounting_artifact_mutation();

DROP TRIGGER IF EXISTS ledger_entries_immutability ON ledger_entries;
CREATE TRIGGER ledger_entries_immutability
    BEFORE UPDATE OR DELETE ON ledger_entries
    FOR EACH ROW
    EXECUTE FUNCTION prevent_accounting_artifact_mutation();

-- B) Tenant consistency triggers
CREATE OR REPLACE FUNCTION validate_posting_group_tenant()
RETURNS TRIGGER AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM projects 
        WHERE id = NEW.project_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'PostingGroup tenant_id (%) does not match project tenant_id', NEW.tenant_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS posting_groups_tenant_check ON posting_groups;
CREATE TRIGGER posting_groups_tenant_check
    BEFORE INSERT ON posting_groups
    FOR EACH ROW
    EXECUTE FUNCTION validate_posting_group_tenant();

CREATE OR REPLACE FUNCTION validate_allocation_row_tenant()
RETURNS TRIGGER AS $$
BEGIN
    -- Check posting_group tenant
    IF NOT EXISTS (
        SELECT 1 FROM posting_groups 
        WHERE id = NEW.posting_group_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'AllocationRow tenant_id (%) does not match posting_group tenant_id', NEW.tenant_id;
    END IF;
    -- Check project tenant
    IF NOT EXISTS (
        SELECT 1 FROM projects 
        WHERE id = NEW.project_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'AllocationRow tenant_id (%) does not match project tenant_id', NEW.tenant_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS allocation_rows_tenant_check ON allocation_rows;
CREATE TRIGGER allocation_rows_tenant_check
    BEFORE INSERT ON allocation_rows
    FOR EACH ROW
    EXECUTE FUNCTION validate_allocation_row_tenant();

CREATE OR REPLACE FUNCTION validate_ledger_entry_tenant()
RETURNS TRIGGER AS $$
BEGIN
    -- Check posting_group tenant
    IF NOT EXISTS (
        SELECT 1 FROM posting_groups 
        WHERE id = NEW.posting_group_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'LedgerEntry tenant_id (%) does not match posting_group tenant_id', NEW.tenant_id;
    END IF;
    -- Check account tenant
    IF NOT EXISTS (
        SELECT 1 FROM accounts 
        WHERE id = NEW.account_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'LedgerEntry tenant_id (%) does not match account tenant_id', NEW.tenant_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS ledger_entries_tenant_check ON ledger_entries;
CREATE TRIGGER ledger_entries_tenant_check
    BEFORE INSERT ON ledger_entries
    FOR EACH ROW
    EXECUTE FUNCTION validate_ledger_entry_tenant();

-- C) Closed cycle lock: Prevent posting to closed crop cycles
CREATE OR REPLACE FUNCTION validate_crop_cycle_open()
RETURNS TRIGGER AS $$
DECLARE
    cycle_status crop_cycle_status;
BEGIN
    SELECT cc.status INTO cycle_status
    FROM projects p
    JOIN crop_cycles cc ON p.crop_cycle_id = cc.id
    WHERE p.id = NEW.project_id;
    
    IF cycle_status = 'CLOSED' THEN
        RAISE EXCEPTION 'Cannot post to project with CLOSED crop cycle';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS posting_groups_crop_cycle_check ON posting_groups;
CREATE TRIGGER posting_groups_crop_cycle_check
    BEFORE INSERT ON posting_groups
    FOR EACH ROW
    EXECUTE FUNCTION validate_crop_cycle_open();

-- D) Posting date validation: Must be within crop cycle date range
CREATE OR REPLACE FUNCTION validate_posting_date_range()
RETURNS TRIGGER AS $$
DECLARE
    cycle_start DATE;
    cycle_end DATE;
BEGIN
    SELECT cc.start_date, cc.end_date INTO cycle_start, cycle_end
    FROM projects p
    JOIN crop_cycles cc ON p.crop_cycle_id = cc.id
    WHERE p.id = NEW.project_id;
    
    IF NEW.posting_date < cycle_start OR NEW.posting_date > cycle_end THEN
        RAISE EXCEPTION 'Posting date (%) must be between crop cycle dates (%) and (%)', 
            NEW.posting_date, cycle_start, cycle_end;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS posting_groups_date_range_check ON posting_groups;
CREATE TRIGGER posting_groups_date_range_check
    BEFORE INSERT ON posting_groups
    FOR EACH ROW
    EXECUTE FUNCTION validate_posting_date_range();

-- E) Double-entry balance: Deferrable constraint trigger
CREATE OR REPLACE FUNCTION check_posting_group_balance()
RETURNS TRIGGER AS $$
DECLARE
    pg_id UUID;
    total_debit NUMERIC(14,2);
    total_credit NUMERIC(14,2);
BEGIN
    -- Determine which posting_group_id to check
    IF TG_OP = 'DELETE' THEN
        pg_id := OLD.posting_group_id;
    ELSE
        pg_id := NEW.posting_group_id;
    END IF;
    
    -- Calculate totals for the posting group
    SELECT 
        COALESCE(SUM(debit), 0),
        COALESCE(SUM(credit), 0)
    INTO total_debit, total_credit
    FROM ledger_entries
    WHERE posting_group_id = pg_id;
    
    -- Check balance
    IF total_debit != total_credit THEN
        RAISE EXCEPTION 'PostingGroup % is not balanced: debit=%, credit=%', 
            pg_id, total_debit, total_credit;
    END IF;
    
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    ELSE
        RETURN NEW;
    END IF;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS ledger_entries_balance_check ON ledger_entries;
CREATE TRIGGER ledger_entries_balance_check
    AFTER INSERT OR UPDATE OR DELETE ON ledger_entries
    FOR EACH ROW
    EXECUTE FUNCTION check_posting_group_balance();

-- ============================================================================
-- SEED DATA: Phase 1 + 3
-- ============================================================================

-- Create one OPEN crop cycle for demo tenant
INSERT INTO crop_cycles (id, tenant_id, name, start_date, end_date, status) VALUES 
    ('00000000-0000-0000-0000-000000000020', '00000000-0000-0000-0000-000000000001', '2024 Crop Cycle', '2024-01-01', '2024-12-31', 'OPEN')
ON CONFLICT (id) DO NOTHING;

-- Update existing projects to reference the crop cycle
UPDATE projects 
SET crop_cycle_id = '00000000-0000-0000-0000-000000000020'
WHERE tenant_id = '00000000-0000-0000-0000-000000000001' 
  AND crop_cycle_id IS NULL;

-- Now make crop_cycle_id NOT NULL (after assigning existing projects)
-- Note: This will fail if there are any NULL values, so we ensure all are set above
DO $$ 
BEGIN
    -- Check if all projects have crop_cycle_id
    IF EXISTS (SELECT 1 FROM projects WHERE crop_cycle_id IS NULL) THEN
        RAISE EXCEPTION 'Cannot make crop_cycle_id NOT NULL: some projects are missing crop_cycle_id';
    END IF;
    
    -- Add NOT NULL constraint if not already present
    ALTER TABLE projects ALTER COLUMN crop_cycle_id SET NOT NULL;
EXCEPTION
    WHEN others THEN
        -- Constraint might already exist, ignore
        NULL;
END $$;

-- Create accounts for demo tenant (CASH, EXPENSES, INCOME)
INSERT INTO accounts (id, tenant_id, code, name, type) VALUES 
    ('00000000-0000-0000-0000-000000000030', '00000000-0000-0000-0000-000000000001', 'CASH', 'Cash', 'ASSET'),
    ('00000000-0000-0000-0000-000000000031', '00000000-0000-0000-0000-000000000001', 'EXPENSES', 'Expenses', 'EXPENSE'),
    ('00000000-0000-0000-0000-000000000032', '00000000-0000-0000-0000-000000000001', 'INCOME', 'Income', 'INCOME')
ON CONFLICT (id) DO NOTHING;

-- ============================================================================
-- PHASE 4 + 5: Rule Configuration and Reversal Support
-- ============================================================================

-- Daily Book Account Mappings table (rule configuration)
CREATE TABLE IF NOT EXISTS daily_book_account_mappings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    version TEXT NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    expense_debit_account_id UUID NOT NULL REFERENCES accounts(id),
    expense_credit_account_id UUID NOT NULL REFERENCES accounts(id),
    income_debit_account_id UUID NOT NULL REFERENCES accounts(id),
    income_credit_account_id UUID NOT NULL REFERENCES accounts(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(tenant_id, version, effective_from),
    CONSTRAINT daily_book_account_mappings_date_range CHECK (effective_to IS NULL OR effective_to >= effective_from)
);

CREATE INDEX IF NOT EXISTS idx_daily_book_account_mappings_tenant ON daily_book_account_mappings(tenant_id);
CREATE INDEX IF NOT EXISTS idx_daily_book_account_mappings_effective ON daily_book_account_mappings(tenant_id, effective_from, effective_to);

-- Trigger to ensure mapping account_ids belong to same tenant_id
CREATE OR REPLACE FUNCTION validate_daily_book_account_mapping_tenant()
RETURNS TRIGGER AS $$
BEGIN
    -- Check expense_debit_account_id
    IF NOT EXISTS (
        SELECT 1 FROM accounts 
        WHERE id = NEW.expense_debit_account_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'expense_debit_account_id does not belong to tenant_id %', NEW.tenant_id;
    END IF;
    
    -- Check expense_credit_account_id
    IF NOT EXISTS (
        SELECT 1 FROM accounts 
        WHERE id = NEW.expense_credit_account_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'expense_credit_account_id does not belong to tenant_id %', NEW.tenant_id;
    END IF;
    
    -- Check income_debit_account_id
    IF NOT EXISTS (
        SELECT 1 FROM accounts 
        WHERE id = NEW.income_debit_account_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'income_debit_account_id does not belong to tenant_id %', NEW.tenant_id;
    END IF;
    
    -- Check income_credit_account_id
    IF NOT EXISTS (
        SELECT 1 FROM accounts 
        WHERE id = NEW.income_credit_account_id AND tenant_id = NEW.tenant_id
    ) THEN
        RAISE EXCEPTION 'income_credit_account_id does not belong to tenant_id %', NEW.tenant_id;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS daily_book_account_mappings_tenant_check ON daily_book_account_mappings;
CREATE TRIGGER daily_book_account_mappings_tenant_check
    BEFORE INSERT OR UPDATE ON daily_book_account_mappings
    FOR EACH ROW
    EXECUTE FUNCTION validate_daily_book_account_mapping_tenant();

-- Seed mapping for demo tenant (v1, effective from crop cycle start_date)
INSERT INTO daily_book_account_mappings (
    id, tenant_id, version, effective_from, effective_to,
    expense_debit_account_id, expense_credit_account_id,
    income_debit_account_id, income_credit_account_id
) VALUES (
    '00000000-0000-0000-0000-000000000040',
    '00000000-0000-0000-0000-000000000001',
    'v1',
    '2024-01-01',
    NULL,
    '00000000-0000-0000-0000-000000000031', -- EXPENSES
    '00000000-0000-0000-0000-000000000030', -- CASH
    '00000000-0000-0000-0000-000000000030', -- CASH
    '00000000-0000-0000-0000-000000000032'  -- INCOME
)
ON CONFLICT (id) DO NOTHING;

-- ============================================================================
-- PHASE 6: Reporting & Restatement Semantics (READ-ONLY)
-- ============================================================================

-- View: v_ledger_lines
-- Purpose: Normalized ledger lines with joined context for reporting
-- Note: Tenant-scoped by column; API must filter by tenant_id
CREATE OR REPLACE VIEW v_ledger_lines AS
SELECT
  le.tenant_id,
  pg.id AS posting_group_id,
  pg.posting_date,
  pg.project_id,
  pg.source_type,
  pg.source_id,
  pg.reversal_of_posting_group_id,
  pg.correction_reason,
  le.id AS ledger_entry_id,
  le.account_id,
  a.code AS account_code,
  a.name AS account_name,
  a.type AS account_type,
  le.currency_code,
  le.debit,
  le.credit,
  (le.debit - le.credit) AS net
FROM ledger_entries le
JOIN posting_groups pg ON pg.id = le.posting_group_id
JOIN accounts a ON a.id = le.account_id;

-- View: v_trial_balance
-- Purpose: Trial balance by tenant, account (aggregated)
-- Note: API must apply WHERE posting_date BETWEEN :from AND :to (or <= :as_of) and tenant_id = :tenant_id
-- Do NOT bake a date window into the view
CREATE OR REPLACE VIEW v_trial_balance AS
SELECT
  tenant_id,
  account_id,
  account_code,
  account_name,
  account_type,
  currency_code,
  SUM(debit) AS total_debit,
  SUM(credit) AS total_credit,
  SUM(net) AS net
FROM v_ledger_lines
GROUP BY tenant_id, account_id, account_code, account_name, account_type, currency_code;
