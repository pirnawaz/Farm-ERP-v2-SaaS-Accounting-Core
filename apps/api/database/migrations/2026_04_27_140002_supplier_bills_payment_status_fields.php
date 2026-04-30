<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->string('payment_status', 32)->nullable(false)->default('UNPAID')->after('status');
            $table->decimal('paid_amount', 18, 2)->nullable(false)->default(0)->after('payment_status');
            $table->decimal('outstanding_amount', 18, 2)->nullable(false)->default(0)->after('paid_amount');
            $table->index(['tenant_id', 'payment_status']);
        });

        DB::statement("ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_payment_status_check CHECK (payment_status IN ('UNPAID', 'PARTIALLY_PAID', 'PAID'))");
        DB::statement('ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_payment_amounts_non_negative CHECK (paid_amount >= 0 AND outstanding_amount >= 0)');

        // Initialize outstanding from grand_total for existing rows.
        DB::statement('UPDATE supplier_bills SET outstanding_amount = grand_total WHERE outstanding_amount = 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_bills DROP CONSTRAINT IF EXISTS supplier_bills_payment_amounts_non_negative');
        DB::statement('ALTER TABLE supplier_bills DROP CONSTRAINT IF EXISTS supplier_bills_payment_status_check');
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'payment_status']);
            $table->dropColumn(['payment_status', 'paid_amount', 'outstanding_amount']);
        });
    }
};

