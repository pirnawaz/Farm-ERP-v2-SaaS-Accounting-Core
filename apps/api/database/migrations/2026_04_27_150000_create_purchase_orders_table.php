<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            CREATE TYPE purchase_order_status AS ENUM ('DRAFT', 'APPROVED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_id')->nullable(false);
            $table->string('po_no')->nullable(false);
            $table->date('po_date')->nullable(false);
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->text('notes')->nullable();

            $table->timestamptz('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('approved_by')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');

            $table->unique(['tenant_id', 'po_no']);
            $table->index(['tenant_id']);
            $table->index(['status']);
            $table->index(['supplier_id']);
            $table->index(['po_date']);
        });

        DB::statement('ALTER TABLE purchase_orders ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN status');
        DB::statement("ALTER TABLE purchase_orders ADD COLUMN status purchase_order_status NOT NULL DEFAULT 'DRAFT'");
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        DB::statement('DROP TYPE IF EXISTS purchase_order_status');
    }
};

