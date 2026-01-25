<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name')->nullable(false);
            $table->jsonb('party_types')->nullable(false); // Array of party types: Hari, Kamdar, Vendor, Buyer, Lender, Contractor
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id']);
            $table->index(['name']); // For search functionality
        });
        
        DB::statement('ALTER TABLE parties ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
