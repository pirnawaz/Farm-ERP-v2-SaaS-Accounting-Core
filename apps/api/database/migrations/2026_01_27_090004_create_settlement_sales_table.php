<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('settlement_id')->nullable(false);
            $table->uuid('sale_id')->nullable(false);
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('settlement_id')->references('id')->on('settlements')->onDelete('cascade');
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->index(['tenant_id']);
            $table->index(['settlement_id']);
            $table->index(['sale_id']);
            $table->unique(['settlement_id', 'sale_id'], 'settlement_sales_settlement_sale_unique');
        });
        
        DB::statement('ALTER TABLE settlement_sales ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_sales');
    }
};
