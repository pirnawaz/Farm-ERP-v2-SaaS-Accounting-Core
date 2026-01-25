<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('sale_id')->nullable(false);
            $table->uuid('payment_id')->nullable(false);
            $table->uuid('posting_group_id')->nullable(false);
            $table->date('allocation_date')->nullable(false);
            $table->decimal('amount', 12, 2)->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            
            // Unique constraint for idempotency: same payment + sale + posting_group can't be allocated twice
            $table->unique(['tenant_id', 'payment_id', 'sale_id', 'posting_group_id'], 'sale_payment_allocations_unique');
            
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'allocation_date']);
        });
        
        DB::statement('ALTER TABLE sale_payment_allocations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Add CHECK constraint: amount must be greater than 0
        DB::statement('ALTER TABLE sale_payment_allocations ADD CONSTRAINT sale_payment_allocations_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_payment_allocations DROP CONSTRAINT IF EXISTS sale_payment_allocations_amount_check');
        
        Schema::dropIfExists('sale_payment_allocations');
    }
};
