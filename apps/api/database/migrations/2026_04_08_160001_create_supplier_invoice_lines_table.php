<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_invoice_id')->nullable(false);
            $table->unsignedInteger('line_no')->nullable(false)->default(1);
            $table->text('description')->nullable();
            $table->uuid('item_id')->nullable();
            $table->decimal('qty', 18, 6)->nullable();
            $table->decimal('unit_price', 18, 6)->nullable();
            $table->decimal('line_total', 18, 2)->nullable(false)->default(0);
            $table->decimal('tax_amount', 18, 2)->nullable(false)->default(0);
            $table->uuid('grn_line_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inv_items');
            $table->foreign('grn_line_id')->references('id')->on('inv_grn_lines')->nullOnDelete();
            $table->index(['tenant_id']);
            $table->index(['supplier_invoice_id']);
            $table->index(['grn_line_id']);
        });

        DB::statement('ALTER TABLE supplier_invoice_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_lines');
    }
};
