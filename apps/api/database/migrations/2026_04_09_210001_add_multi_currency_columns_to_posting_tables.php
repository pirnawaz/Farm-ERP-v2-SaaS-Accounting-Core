<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable()->after('posting_date');
            $table->char('base_currency_code', 3)->nullable()->after('currency_code');
            $table->decimal('fx_rate', 18, 8)->nullable()->after('base_currency_code');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->char('base_currency_code', 3)->nullable()->after('currency_code');
            $table->decimal('fx_rate', 18, 8)->nullable()->after('base_currency_code');
            $table->decimal('debit_amount_base', 14, 2)->nullable()->after('fx_rate');
            $table->decimal('credit_amount_base', 14, 2)->nullable()->after('debit_amount_base');
        });

        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable()->after('amount');
            $table->char('base_currency_code', 3)->nullable()->after('currency_code');
            $table->decimal('fx_rate', 18, 8)->nullable()->after('base_currency_code');
            $table->decimal('amount_base', 14, 2)->nullable()->after('fx_rate');
        });

        DB::statement('
            UPDATE ledger_entries AS le
            SET
                base_currency_code = le.currency_code,
                fx_rate = 1,
                debit_amount_base = le.debit_amount,
                credit_amount_base = le.credit_amount
        ');

        DB::statement('
            UPDATE posting_groups AS pg
            SET
                base_currency_code = t.currency_code,
                fx_rate = 1,
                currency_code = t.currency_code
            FROM tenants AS t
            WHERE pg.tenant_id = t.id
        ');

        DB::statement('
            UPDATE allocation_rows AS ar
            SET
                currency_code = t.currency_code,
                base_currency_code = t.currency_code,
                fx_rate = 1,
                amount_base = ar.amount
            FROM tenants AS t
            WHERE ar.tenant_id = t.id AND ar.amount IS NOT NULL
        ');

        DB::statement('
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_base_debit_nonneg CHECK (debit_amount_base IS NULL OR debit_amount_base >= 0)
        ');
        DB::statement('
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_base_credit_nonneg CHECK (credit_amount_base IS NULL OR credit_amount_base >= 0)
        ');
        DB::statement('
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_base_exclusive CHECK (
                NOT (COALESCE(debit_amount_base, 0) > 0 AND COALESCE(credit_amount_base, 0) > 0)
            )
        ');

        DB::statement('
            ALTER TABLE allocation_rows ADD CONSTRAINT allocation_rows_amount_base_nonneg CHECK (amount_base IS NULL OR amount_base >= 0)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_base_debit_nonneg');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_base_credit_nonneg');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_base_exclusive');
        DB::statement('ALTER TABLE allocation_rows DROP CONSTRAINT IF EXISTS allocation_rows_amount_base_nonneg');

        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'base_currency_code', 'fx_rate', 'amount_base']);
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['base_currency_code', 'fx_rate', 'debit_amount_base', 'credit_amount_base']);
        });

        Schema::table('posting_groups', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'base_currency_code', 'fx_rate']);
        });
    }
};
