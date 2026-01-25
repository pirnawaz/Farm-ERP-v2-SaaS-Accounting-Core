<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_offsets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('settlement_id')->nullable(false);
            $table->uuid('party_id')->nullable(false);
            $table->uuid('posting_group_id')->nullable(false);
            $table->date('posting_date')->nullable(false);
            $table->decimal('offset_amount', 12, 2)->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('settlement_id')->references('id')->on('settlements');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            
            $table->index(['tenant_id']);
            $table->index(['settlement_id']);
            $table->index(['party_id']);
            $table->index(['posting_group_id']);
            
            // If only one Hari per settlement, enforce unique(settlement_id, party_id)
            // Otherwise, allow multiple offsets per settlement (for multiple Haris)
            $table->unique(['settlement_id', 'party_id'], 'settlement_offsets_settlement_party_unique');
        });
        
        DB::statement('ALTER TABLE settlement_offsets ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Ensure offset_amount > 0 using CHECK constraint
        DB::statement('ALTER TABLE settlement_offsets ADD CONSTRAINT settlement_offsets_offset_amount_check CHECK (offset_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_offsets');
    }
};
