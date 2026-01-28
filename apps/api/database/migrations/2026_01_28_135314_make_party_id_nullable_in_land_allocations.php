<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing foreign key constraint
        Schema::table('land_allocations', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
        });

        // Make party_id nullable
        Schema::table('land_allocations', function (Blueprint $table) {
            $table->uuid('party_id')->nullable()->change();
        });

        // Re-add foreign key constraint that allows NULL
        Schema::table('land_allocations', function (Blueprint $table) {
            $table->foreign('party_id')->references('id')->on('parties')->onDelete('restrict');
        });

        // Add partial unique indexes to prevent duplicates
        // Index for OWNER allocations (party_id IS NULL)
        DB::statement('CREATE UNIQUE INDEX land_allocations_owner_unique ON land_allocations (crop_cycle_id, land_parcel_id) WHERE party_id IS NULL');
        
        // Index for HARI allocations (party_id IS NOT NULL)
        DB::statement('CREATE UNIQUE INDEX land_allocations_hari_unique ON land_allocations (crop_cycle_id, land_parcel_id, party_id) WHERE party_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the partial unique indexes
        DB::statement('DROP INDEX IF EXISTS land_allocations_owner_unique');
        DB::statement('DROP INDEX IF EXISTS land_allocations_hari_unique');

        // Drop the foreign key constraint
        Schema::table('land_allocations', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
        });

        // Make party_id NOT NULL again
        Schema::table('land_allocations', function (Blueprint $table) {
            $table->uuid('party_id')->nullable(false)->change();
        });

        // Re-add the original foreign key constraint
        Schema::table('land_allocations', function (Blueprint $table) {
            $table->foreign('party_id')->references('id')->on('parties');
        });
    }
};
