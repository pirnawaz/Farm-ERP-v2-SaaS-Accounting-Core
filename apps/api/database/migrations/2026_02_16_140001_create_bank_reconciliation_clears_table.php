<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clears are reconciliation metadata only; ledger_entries are immutable.
     * One active CLEAR per ledger_entry_id (partial unique).
     */
    public function up(): void
    {
        Schema::create('bank_reconciliation_clears', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('bank_reconciliation_id')->nullable(false);
            $table->uuid('ledger_entry_id')->nullable(false);
            $table->date('cleared_date')->nullable(false);
            $table->string('status', 20)->default('CLEARED');
            $table->uuid('created_by')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bank_reconciliation_id')->references('id')->on('bank_reconciliations');
            $table->foreign('ledger_entry_id')->references('id')->on('ledger_entries');
            $table->index(['tenant_id', 'bank_reconciliation_id']);
            $table->index(['tenant_id', 'ledger_entry_id']);
        });

        DB::statement('ALTER TABLE bank_reconciliation_clears ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE bank_reconciliation_clears ADD CONSTRAINT bank_reconciliation_clears_status_check CHECK (status IN ('CLEARED', 'VOID'))");
        DB::statement("CREATE UNIQUE INDEX bank_reconciliation_clears_ledger_entry_active ON bank_reconciliation_clears (ledger_entry_id) WHERE (status = 'CLEARED')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_reconciliation_clears_ledger_entry_active');
        DB::statement('ALTER TABLE bank_reconciliation_clears DROP CONSTRAINT IF EXISTS bank_reconciliation_clears_status_check');
        Schema::dropIfExists('bank_reconciliation_clears');
    }
};
