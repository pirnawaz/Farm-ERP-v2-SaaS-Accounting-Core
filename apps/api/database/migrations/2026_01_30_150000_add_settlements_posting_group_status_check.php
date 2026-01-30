<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Constraint name for down(). */
    private const CONSTRAINT_NAME = 'settlements_posting_group_id_status_check';

    /**
     * Add CHECK constraint: POSTED/REVERSED require posting_group_id NOT NULL;
     * DRAFT may have posting_group_id NULL.
     * Other statuses (e.g. future CANCELLED/VOID): allow either for legacy safety.
     * Logic: (status = 'DRAFT') OR (posting_group_id IS NOT NULL).
     */
    public function up(): void
    {
        DB::statement(sprintf(
            "ALTER TABLE settlements ADD CONSTRAINT %s CHECK (
                (status = 'DRAFT') OR (posting_group_id IS NOT NULL)
            )",
            self::CONSTRAINT_NAME
        ));
    }

    public function down(): void
    {
        DB::statement(sprintf(
            'ALTER TABLE settlements DROP CONSTRAINT IF EXISTS %s',
            self::CONSTRAINT_NAME
        ));
    }
};
