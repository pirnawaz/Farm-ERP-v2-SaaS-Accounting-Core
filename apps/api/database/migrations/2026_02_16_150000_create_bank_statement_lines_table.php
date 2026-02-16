<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Manual statement lines per reconciliation: date, amount (signed), description/reference.
     * No ledger mutation; ACTIVE/VOID for audit.
     */
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('bank_reconciliation_id')->nullable(false);
            $table->date('line_date')->nullable(false);
            $table->decimal('amount', 14, 2)->nullable(false); // signed: deposits > 0, withdrawals < 0
            $table->text('description')->nullable();
            $table->text('reference')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->uuid('created_by')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bank_reconciliation_id')->references('id')->on('bank_reconciliations');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('voided_by')->references('id')->on('users');
            $table->index(['tenant_id', 'bank_reconciliation_id']);
        });

        DB::statement('ALTER TABLE bank_statement_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_status_check CHECK (status IN ('ACTIVE', 'VOID'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_statement_lines DROP CONSTRAINT IF EXISTS bank_statement_lines_status_check');
        Schema::dropIfExists('bank_statement_lines');
    }
};
