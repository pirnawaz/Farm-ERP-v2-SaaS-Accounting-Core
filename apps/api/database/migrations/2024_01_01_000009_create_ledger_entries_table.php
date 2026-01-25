<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('posting_group_id')->nullable(false);
            $table->uuid('account_id')->nullable(false);
            $table->decimal('debit', 14, 2)->default(0)->nullable(false);
            $table->decimal('credit', 14, 2)->default(0)->nullable(false);
            $table->char('currency_code', 3)->default('GBP')->nullable(false);
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'posting_group_id']);
            $table->index(['tenant_id', 'account_id']);
        });
        
        DB::statement('ALTER TABLE ledger_entries ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_check CHECK (debit >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_credit_check CHECK (credit >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_credit_exclusive CHECK (NOT (debit > 0 AND credit > 0))');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_credit_required CHECK ((debit > 0) OR (credit > 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
