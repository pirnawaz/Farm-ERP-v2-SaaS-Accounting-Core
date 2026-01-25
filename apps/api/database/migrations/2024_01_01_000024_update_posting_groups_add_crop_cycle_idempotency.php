<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for posting group source type
        DB::statement("DO $$ BEGIN
            CREATE TYPE posting_group_source_type AS ENUM ('OPERATIONAL', 'SETTLEMENT', 'ADJUSTMENT');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Add crop_cycle_id column (nullable first, then populate from projects)
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->uuid('crop_cycle_id')->nullable()->after('tenant_id');
        });

        // Populate crop_cycle_id from projects table for existing rows
        // This must happen before we drop project_id
        DB::statement('UPDATE posting_groups SET crop_cycle_id = (SELECT crop_cycle_id FROM projects WHERE projects.id = posting_groups.project_id) WHERE crop_cycle_id IS NULL');

        // Now make it NOT NULL
        DB::statement('ALTER TABLE posting_groups ALTER COLUMN crop_cycle_id SET NOT NULL');

        // Add foreign key for crop_cycle_id
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
        });

        // Add idempotency_key column
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('posting_date');
        });

        // Remove project_id column (no longer needed at posting_group level)
        // This happens AFTER we've populated crop_cycle_id
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });

        // Update source_type to use ENUM
        // First, ensure all existing values are valid
        DB::statement("UPDATE posting_groups SET source_type = 'OPERATIONAL' WHERE source_type NOT IN ('OPERATIONAL', 'SETTLEMENT', 'ADJUSTMENT')");
        DB::statement('ALTER TABLE posting_groups DROP COLUMN source_type');
        DB::statement("ALTER TABLE posting_groups ADD COLUMN source_type posting_group_source_type NOT NULL DEFAULT 'OPERATIONAL'");

        // Add indexes
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->index('crop_cycle_id');
            $table->index('idempotency_key');
            $table->unique(['tenant_id', 'idempotency_key'], 'posting_groups_tenant_idempotency_unique');
        });

        // Update unique constraint to remove project_id
        DB::statement('ALTER TABLE posting_groups DROP CONSTRAINT IF EXISTS posting_groups_tenant_id_source_type_source_id_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS posting_groups_tenant_source_unique ON posting_groups(tenant_id, source_type, source_id)');

        // Note: posting_date within crop cycle range validation is enforced at service layer
        // Note: crop_cycle.status = OPEN validation is enforced at service layer
    }

    public function down(): void
    {
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->dropUnique('posting_groups_tenant_idempotency_unique');
            $table->dropIndex(['idempotency_key']);
            $table->dropIndex(['crop_cycle_id']);
        });

        DB::statement('DROP INDEX IF EXISTS posting_groups_tenant_source_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS posting_groups_tenant_id_source_type_source_id_unique ON posting_groups(tenant_id, source_type, source_id)');

        DB::statement('ALTER TABLE posting_groups DROP COLUMN source_type');
        DB::statement("ALTER TABLE posting_groups ADD COLUMN source_type VARCHAR(255) NOT NULL");

        Schema::table('posting_groups', function (Blueprint $table) {
            $table->uuid('project_id')->nullable(false)->after('tenant_id');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->index('project_id');
            $table->dropColumn(['idempotency_key', 'crop_cycle_id']);
        });

        DB::statement('DROP TYPE IF EXISTS posting_group_source_type');
    }
};
