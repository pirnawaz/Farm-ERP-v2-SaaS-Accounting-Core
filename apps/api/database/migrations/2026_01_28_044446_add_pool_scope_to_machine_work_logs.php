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
        // Create Postgres ENUM type for pool scope
        DB::statement("DO $$ BEGIN
            CREATE TYPE machine_work_log_pool_scope AS ENUM ('SHARED', 'HARI_ONLY');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Add pool_scope column (nullable first, then set default)
        Schema::table('machine_work_logs', function (Blueprint $table) {
            $table->string('pool_scope')->nullable()->after('crop_cycle_id');
        });

        // Set default value for existing rows
        DB::statement("UPDATE machine_work_logs SET pool_scope = 'SHARED' WHERE pool_scope IS NULL");

        // Convert to ENUM
        DB::statement('ALTER TABLE machine_work_logs DROP COLUMN pool_scope');
        DB::statement("ALTER TABLE machine_work_logs ADD COLUMN pool_scope machine_work_log_pool_scope NOT NULL DEFAULT 'SHARED'");

        // Add activity_id column (nullable, for future crop_ops linking)
        Schema::table('machine_work_logs', function (Blueprint $table) {
            $table->uuid('activity_id')->nullable()->after('pool_scope');
            $table->foreign('activity_id')->references('id')->on('crop_activities');
        });

        // Add index on (tenant_id, pool_scope)
        Schema::table('machine_work_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'pool_scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machine_work_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'pool_scope']);
            $table->dropForeign(['activity_id']);
            $table->dropColumn(['activity_id', 'pool_scope']);
        });

        DB::statement('DROP TYPE IF EXISTS machine_work_log_pool_scope');
    }
};
