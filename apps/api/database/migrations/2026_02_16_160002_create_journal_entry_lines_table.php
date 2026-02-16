<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * General Journal lines: account, debit_amount, credit_amount (exactly one positive per line).
     */
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false)->index();
            $table->uuid('journal_entry_id')->nullable(false)->index();
            $table->uuid('account_id')->nullable(false);
            $table->text('description')->nullable();
            $table->decimal('debit_amount', 14, 2)->default(0);
            $table->decimal('credit_amount', 14, 2)->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->index(['journal_entry_id', 'account_id']);
        });

        // Exactly one of debit_amount or credit_amount > 0 per line
        \DB::statement('ALTER TABLE journal_entry_lines ADD CONSTRAINT journal_entry_lines_debit_credit_xor CHECK (
            (debit_amount > 0 AND credit_amount = 0) OR (debit_amount = 0 AND credit_amount > 0)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
