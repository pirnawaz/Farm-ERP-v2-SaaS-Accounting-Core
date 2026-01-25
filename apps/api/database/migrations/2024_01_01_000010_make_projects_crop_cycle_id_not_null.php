<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure all projects have a crop_cycle_id before making it NOT NULL
        // This assumes seed data has been run
        // In production, you would ensure all projects are assigned to a crop cycle
        
        Schema::table('projects', function (Blueprint $table) {
            // First, ensure no NULL values exist
            // If there are NULL values, this migration will fail
            // This is intentional - all projects must have a crop cycle
            $table->uuid('crop_cycle_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('crop_cycle_id')->nullable()->change();
        });
    }
};
