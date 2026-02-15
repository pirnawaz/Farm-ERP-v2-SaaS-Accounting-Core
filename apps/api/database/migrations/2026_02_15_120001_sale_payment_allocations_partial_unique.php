<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow re-apply after unapply: only one ACTIVE allocation per (tenant, payment, sale, posting_group).
     * VOID rows are kept for audit; new ACTIVE row can be created for same payment+sale.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE sale_payment_allocations DROP CONSTRAINT IF EXISTS sale_payment_allocations_unique');
        DB::statement("CREATE UNIQUE INDEX sale_payment_allocations_active_unique ON sale_payment_allocations (tenant_id, payment_id, sale_id, posting_group_id) WHERE (status = 'ACTIVE' OR status IS NULL)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sale_payment_allocations_active_unique');
        DB::statement('ALTER TABLE sale_payment_allocations ADD CONSTRAINT sale_payment_allocations_unique UNIQUE (tenant_id, payment_id, sale_id, posting_group_id)');
    }
};
