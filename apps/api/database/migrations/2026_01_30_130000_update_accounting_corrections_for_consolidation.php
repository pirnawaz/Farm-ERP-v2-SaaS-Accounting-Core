<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Update accounting_corrections to support consolidation use case:
     * - original_posting_group_id can be NULL (for consolidation, not correcting existing PG)
     * - reversal_posting_group_id can be NULL (consolidation doesn't need reversal)
     * - Add unique constraint on (tenant_id, reason) for consolidation idempotency
     */
    public function up(): void
    {
        Schema::table('accounting_corrections', function (Blueprint $table) {
            // Drop existing unique constraint
            $table->dropUnique('accounting_corrections_tenant_original_pg_unique');
            
            // Drop foreign keys temporarily
            $table->dropForeign(['original_posting_group_id']);
            $table->dropForeign(['reversal_posting_group_id']);
        });

        // Make columns nullable
        DB::statement('ALTER TABLE accounting_corrections ALTER COLUMN original_posting_group_id DROP NOT NULL');
        DB::statement('ALTER TABLE accounting_corrections ALTER COLUMN reversal_posting_group_id DROP NOT NULL');

        Schema::table('accounting_corrections', function (Blueprint $table) {
            // Re-add foreign keys with nullable support
            $table->foreign('original_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            
            // Add unique constraint for (tenant_id, original_posting_group_id) when original_posting_group_id is not null
            // This is handled via a partial unique index in PostgreSQL
            DB::statement('CREATE UNIQUE INDEX accounting_corrections_tenant_original_pg_unique ON accounting_corrections(tenant_id, original_posting_group_id) WHERE original_posting_group_id IS NOT NULL');
            
            // Add unique constraint for (tenant_id, reason) when original_posting_group_id IS NULL (for consolidation use case)
            // This ensures only one consolidation per tenant+reason
            DB::statement('CREATE UNIQUE INDEX accounting_corrections_tenant_reason_unique ON accounting_corrections(tenant_id, reason) WHERE original_posting_group_id IS NULL');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_corrections', function (Blueprint $table) {
            // Drop new constraints
            DB::statement('DROP INDEX IF EXISTS accounting_corrections_tenant_reason_unique');
            DB::statement('DROP INDEX IF EXISTS accounting_corrections_tenant_original_pg_unique');
            
            // Drop foreign keys
            $table->dropForeign(['original_posting_group_id']);
            $table->dropForeign(['reversal_posting_group_id']);
        });

        // Make columns NOT NULL again
        DB::statement('ALTER TABLE accounting_corrections ALTER COLUMN original_posting_group_id SET NOT NULL');
        DB::statement('ALTER TABLE accounting_corrections ALTER COLUMN reversal_posting_group_id SET NOT NULL');

        Schema::table('accounting_corrections', function (Blueprint $table) {
            // Re-add foreign keys
            $table->foreign('original_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            
            // Re-add original unique constraint
            $table->unique(['tenant_id', 'original_posting_group_id'], 'accounting_corrections_tenant_original_pg_unique');
        });
    }
};
