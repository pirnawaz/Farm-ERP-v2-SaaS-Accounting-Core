<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'SUPPLIER_INVOICE'");

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->timestampTz('posted_at')->nullable()->after('posting_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn('posted_at');
        });
        // PostgreSQL: cannot remove enum value SUPPLIER_INVOICE safely; leave type as-is.
    }
};
