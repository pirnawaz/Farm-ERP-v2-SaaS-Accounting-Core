<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('crop_cycle_id')->nullable(false);
            $table->uuid('land_parcel_id')->nullable(false);
            $table->uuid('party_id')->nullable(false); // Hari party
            $table->decimal('allocated_acres', 10, 2)->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->index(['tenant_id']);
            $table->index(['crop_cycle_id']);
            $table->index(['land_parcel_id']);
            $table->index(['party_id']);
        });
        
        DB::statement('ALTER TABLE land_allocations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Add CHECK constraint: allocated_acres must be greater than 0
        DB::statement('ALTER TABLE land_allocations ADD CONSTRAINT land_allocations_allocated_acres_check CHECK (allocated_acres > 0)');
        
        // Note: SUM(allocated_acres) ≤ total_acres validation is enforced at service layer
        // This constraint cannot be enforced at DB level without triggers, which are not used per spec
    }

    public function down(): void
    {
        Schema::dropIfExists('land_allocations');
    }
};
