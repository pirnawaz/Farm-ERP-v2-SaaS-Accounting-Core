<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoice_lines', function (Blueprint $table) {
            $table->decimal('cash_unit_price', 18, 6)->nullable()->after('unit_price');
            $table->decimal('credit_unit_price', 18, 6)->nullable()->after('cash_unit_price');
            $table->decimal('base_cash_amount', 18, 2)->nullable()->after('line_total');
            $table->decimal('selected_unit_price', 18, 6)->nullable()->after('credit_unit_price');
            $table->decimal('credit_premium_amount', 18, 2)->nullable()->after('base_cash_amount');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoice_lines', function (Blueprint $table) {
            $table->dropColumn([
                'cash_unit_price',
                'credit_unit_price',
                'selected_unit_price',
                'base_cash_amount',
                'credit_premium_amount',
            ]);
        });
    }
};

