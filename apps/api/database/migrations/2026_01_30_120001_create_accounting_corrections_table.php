<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit table for automated correction of operational postings that incorrectly
     * used PROFIT_DISTRIBUTION / PROFIT_DISTRIBUTION_CLEARING. Ensures idempotency.
     */
    public function up(): void
    {
        Schema::create('accounting_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('original_posting_group_id')->nullable(false);
            $table->uuid('reversal_posting_group_id')->nullable(false);
            $table->uuid('corrected_posting_group_id')->nullable(false);
            $table->string('reason', 255)->nullable(false)->default('OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION');
            $table->timestampTz('correction_batch_run_at')->nullable(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'original_posting_group_id'], 'accounting_corrections_tenant_original_pg_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('original_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('corrected_posting_group_id')->references('id')->on('posting_groups');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_corrections');
    }
};
