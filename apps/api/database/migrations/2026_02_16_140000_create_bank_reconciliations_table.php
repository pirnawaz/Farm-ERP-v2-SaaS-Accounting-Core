<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bank reconciliation headers: statement date/balance; no ledger mutation.
     */
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('account_id')->nullable(false);
            $table->date('statement_date')->nullable(false);
            $table->decimal('statement_balance', 14, 2)->nullable(false);
            $table->string('status', 20)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('finalized_by')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('finalized_by')->references('id')->on('users');
            $table->index(['tenant_id', 'account_id']);
            $table->index(['tenant_id', 'statement_date']);
        });

        DB::statement('ALTER TABLE bank_reconciliations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE bank_reconciliations ADD CONSTRAINT bank_reconciliations_status_check CHECK (status IN ('DRAFT', 'FINALIZED', 'VOID'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_reconciliations DROP CONSTRAINT IF EXISTS bank_reconciliations_status_check');
        Schema::dropIfExists('bank_reconciliations');
    }
};
