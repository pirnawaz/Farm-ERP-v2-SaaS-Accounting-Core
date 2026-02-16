<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add sale_kind to support CREDIT_NOTE alongside INVOICE (default).
     * Existing sales are backfilled to INVOICE.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('sale_kind', 32)->nullable()->after('status');
        });

        DB::statement("UPDATE sales SET sale_kind = 'INVOICE' WHERE sale_kind IS NULL");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_sale_kind_check CHECK (sale_kind IN ('INVOICE', 'CREDIT_NOTE'))");
        DB::statement("ALTER TABLE sales ALTER COLUMN sale_kind SET DEFAULT 'INVOICE'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_sale_kind_check');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('sale_kind');
        });
    }
};
