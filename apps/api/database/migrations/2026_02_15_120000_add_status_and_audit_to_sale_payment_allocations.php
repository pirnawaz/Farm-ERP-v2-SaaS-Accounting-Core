<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add status (ACTIVE|VOID) and audit columns for apply/unapply receipt reconciliation.
     * Existing rows default to ACTIVE. Unapply sets VOID + voided_at/voided_by (no delete).
     */
    public function up(): void
    {
        Schema::table('sale_payment_allocations', function (Blueprint $table) {
            $table->string('status', 20)->default('ACTIVE')->after('amount');
            $table->uuid('created_by')->nullable()->after('created_at');
            $table->uuid('voided_by')->nullable()->after('created_by');
            $table->timestampTz('voided_at')->nullable()->after('voided_by');
        });

        DB::statement("ALTER TABLE sale_payment_allocations ADD CONSTRAINT sale_payment_allocations_status_check CHECK (status IN ('ACTIVE', 'VOID'))");

        Schema::table('sale_payment_allocations', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payment_allocations', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
        DB::statement('ALTER TABLE sale_payment_allocations DROP CONSTRAINT IF EXISTS sale_payment_allocations_status_check');
        Schema::table('sale_payment_allocations', function (Blueprint $table) {
            $table->dropColumn(['status', 'created_by', 'voided_by', 'voided_at']);
        });
    }
};
