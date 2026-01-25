<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for project status
        DB::statement("DO $$ BEGIN
            CREATE TYPE project_status AS ENUM ('ACTIVE', 'CLOSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Add new columns
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('party_id')->nullable()->after('tenant_id');
            $table->string('status')->default('ACTIVE')->nullable(false)->after('crop_cycle_id');
            $table->uuid('land_allocation_id')->nullable()->after('status');
        });

        // Add foreign keys
        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('land_allocation_id')->references('id')->on('land_allocations');
        });

        // Convert status column to use ENUM type
        DB::statement('ALTER TABLE projects DROP COLUMN status');
        DB::statement("ALTER TABLE projects ADD COLUMN status project_status NOT NULL DEFAULT 'ACTIVE'");

        // Add indexes
        Schema::table('projects', function (Blueprint $table) {
            $table->index('party_id');
            $table->index('status');
            $table->index('land_allocation_id');
        });

        // Ensure crop_cycle_id exists and is NOT NULL (should already be enforced by previous migration)
        // This is a safety check
        DB::statement('UPDATE projects SET crop_cycle_id = (SELECT id FROM crop_cycles LIMIT 1) WHERE crop_cycle_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['land_allocation_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['party_id']);
        });

        DB::statement('ALTER TABLE projects DROP COLUMN status');
        DB::statement("ALTER TABLE projects ADD COLUMN status VARCHAR(255) NOT NULL DEFAULT 'ACTIVE'");

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['land_allocation_id']);
            $table->dropForeign(['party_id']);
            $table->dropColumn(['land_allocation_id', 'party_id', 'status']);
        });

        DB::statement('DROP TYPE IF EXISTS project_status');
    }
};
