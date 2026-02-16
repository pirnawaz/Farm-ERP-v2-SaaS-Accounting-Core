<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 1:1 match of statement line to ledger entry (metadata only).
     * One active MATCH per statement line; one active MATCH per ledger entry.
     */
    public function up(): void
    {
        Schema::create('bank_statement_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('bank_reconciliation_id')->nullable(false);
            $table->uuid('bank_statement_line_id')->nullable(false);
            $table->uuid('ledger_entry_id')->nullable(false);
            $table->string('status', 20)->default('MATCHED');
            $table->uuid('created_by')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bank_reconciliation_id')->references('id')->on('bank_reconciliations');
            $table->foreign('bank_statement_line_id')->references('id')->on('bank_statement_lines');
            $table->foreign('ledger_entry_id')->references('id')->on('ledger_entries');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('voided_by')->references('id')->on('users');
            $table->index(['tenant_id', 'bank_reconciliation_id']);
            $table->index(['tenant_id', 'bank_statement_line_id']);
            $table->index(['tenant_id', 'ledger_entry_id']);
        });

        DB::statement('ALTER TABLE bank_statement_matches ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE bank_statement_matches ADD CONSTRAINT bank_statement_matches_status_check CHECK (status IN ('MATCHED', 'VOID'))");
        DB::statement("CREATE UNIQUE INDEX bank_statement_matches_line_active ON bank_statement_matches (bank_statement_line_id) WHERE (status = 'MATCHED')");
        DB::statement("CREATE UNIQUE INDEX bank_statement_matches_ledger_active ON bank_statement_matches (ledger_entry_id) WHERE (status = 'MATCHED')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_statement_matches_ledger_active');
        DB::statement('DROP INDEX IF EXISTS bank_statement_matches_line_active');
        DB::statement('ALTER TABLE bank_statement_matches DROP CONSTRAINT IF EXISTS bank_statement_matches_status_check');
        Schema::dropIfExists('bank_statement_matches');
    }
};
