<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->date('rate_date')->nullable(false);
            $table->char('base_currency_code', 3)->nullable(false);
            $table->char('quote_currency_code', 3)->nullable(false);
            $table->decimal('rate', 18, 8)->nullable(false);
            $table->string('source')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(
                ['tenant_id', 'rate_date', 'base_currency_code', 'quote_currency_code'],
                'exchange_rates_tenant_date_pair_unique'
            );
            $table->index(['tenant_id', 'rate_date']);
            $table->index(['tenant_id', 'base_currency_code', 'quote_currency_code', 'rate_date']);
        });

        DB::statement('ALTER TABLE exchange_rates ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_positive_rate CHECK (rate > 0)'
        );

        DB::statement(
            "ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_distinct_pair CHECK (base_currency_code <> quote_currency_code)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
