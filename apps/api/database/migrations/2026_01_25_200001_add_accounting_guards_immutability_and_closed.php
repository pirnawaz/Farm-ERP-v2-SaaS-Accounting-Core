<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PR-1: Block UPDATE and DELETE on posting_groups, allocation_rows, ledger_entries.
     * PR-3: Block INSERT into posting_groups when crop_cycle_id references a CLOSED crop_cycles.
     */
    public function up(): void
    {
        // --- PR-1: Immutability (shared function for all three tables) ---
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_block_accounting_mutations()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'UPDATE and DELETE are not allowed on %', TG_TABLE_NAME;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_block_posting_groups_mutation ON posting_groups');
        DB::statement("
            CREATE TRIGGER trg_block_posting_groups_mutation
                BEFORE UPDATE OR DELETE ON posting_groups
                FOR EACH ROW
                EXECUTE FUNCTION fn_block_accounting_mutations()
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_block_allocation_rows_mutation ON allocation_rows');
        DB::statement("
            CREATE TRIGGER trg_block_allocation_rows_mutation
                BEFORE UPDATE OR DELETE ON allocation_rows
                FOR EACH ROW
                EXECUTE FUNCTION fn_block_accounting_mutations()
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_block_ledger_entries_mutation ON ledger_entries');
        DB::statement("
            CREATE TRIGGER trg_block_ledger_entries_mutation
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW
                EXECUTE FUNCTION fn_block_accounting_mutations()
        ");

        // --- PR-3: CLOSED crop cycle lock on posting_groups INSERT ---
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_block_closed_crop_cycle_posting()
            RETURNS TRIGGER AS \$\$
            DECLARE
                cc_status TEXT;
            BEGIN
                IF NEW.crop_cycle_id IS NOT NULL THEN
                    SELECT status INTO cc_status FROM crop_cycles WHERE id = NEW.crop_cycle_id;
                    IF cc_status = 'CLOSED' THEN
                        RAISE EXCEPTION 'Cannot post to a CLOSED crop cycle';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_block_closed_crop_cycle_posting ON posting_groups');
        DB::statement("
            CREATE TRIGGER trg_block_closed_crop_cycle_posting
                BEFORE INSERT ON posting_groups
                FOR EACH ROW
                EXECUTE FUNCTION fn_block_closed_crop_cycle_posting()
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_block_closed_crop_cycle_posting ON posting_groups');
        DB::statement('DROP FUNCTION IF EXISTS fn_block_closed_crop_cycle_posting()');

        DB::statement('DROP TRIGGER IF EXISTS trg_block_posting_groups_mutation ON posting_groups');
        DB::statement('DROP TRIGGER IF EXISTS trg_block_allocation_rows_mutation ON allocation_rows');
        DB::statement('DROP TRIGGER IF EXISTS trg_block_ledger_entries_mutation ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS fn_block_accounting_mutations()');
    }
};
