<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->string('payment_terms', 20)->nullable()->after('currency_code');
        });

        DB::statement('ALTER TABLE supplier_invoices DROP CONSTRAINT IF EXISTS supplier_invoices_payment_terms_check');
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_payment_terms_check CHECK (payment_terms IS NULL OR payment_terms IN ('CASH','CREDIT'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_invoices DROP CONSTRAINT IF EXISTS supplier_invoices_payment_terms_check');
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn('payment_terms');
        });
    }
};

