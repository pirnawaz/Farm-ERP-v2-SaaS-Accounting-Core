<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PR-2: Enforce SUM(debit_amount) = SUM(credit_amount) for each (tenant_id, posting_group_id)
     * in ledger_entries. DEFERRABLE INITIALLY DEFERRED so multiple inserts in one transaction
     * are allowed before the check runs at commit.
     */
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_enforce_posting_group_balance()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM ledger_entries
                    GROUP BY tenant_id, posting_group_id
                    HAVING SUM(debit_amount) <> SUM(credit_amount)
                    LIMIT 1
                ) THEN
                    RAISE EXCEPTION 'Posting group is not balanced: sum(debit) must equal sum(credit) for each (tenant_id, posting_group_id)';
                END IF;
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_ledger_entries_balanced_posting ON ledger_entries');
        DB::statement("
            CREATE CONSTRAINT TRIGGER trg_ledger_entries_balanced_posting
                AFTER INSERT OR UPDATE OR DELETE ON ledger_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION fn_enforce_posting_group_balance()
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_ledger_entries_balanced_posting ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS fn_enforce_posting_group_balance()');
    }
};
