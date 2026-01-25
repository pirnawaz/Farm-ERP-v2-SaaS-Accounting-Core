<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_parcels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name')->nullable(false);
            $table->decimal('total_acres', 10, 2)->nullable(false);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id']);
        });
        
        DB::statement('ALTER TABLE land_parcels ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Add CHECK constraint: total_acres must be greater than 0
        DB::statement('ALTER TABLE land_parcels ADD CONSTRAINT land_parcels_total_acres_check CHECK (total_acres > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('land_parcels');
    }
};
